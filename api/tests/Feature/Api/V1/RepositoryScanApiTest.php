<?php

namespace Tests\Feature\Api\V1;

use App\Enums\FeatureStatus;
use App\Enums\SuggestionStatus;
use App\Enums\VerificationStatus;
use App\Models\Feature;
use App\Models\FeatureCategory;
use App\Models\FeatureSuggestion;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RepositoryScanApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = Sanctum::actingAs(User::factory()->create(), ['*']);
        $this->project = Project::factory()->for($this->user, 'owner')->create();
    }

    private function candidate(string $key, string $name, array $evidence = []): array
    {
        return [
            'key' => $key,
            'name' => $name,
            'evidence' => $evidence ?: [['type' => 'route', 'path' => 'routes/web.php', 'detail' => "GET /{$key}", 'line' => 3]],
        ];
    }

    private function scan(array $candidates)
    {
        return $this->postJson("/api/v1/projects/{$this->project->id}/scans", [
            'scanner' => 'unseen-cli 0.1.0',
            'git_commit' => 'abc1234',
            'candidates' => $candidates,
        ]);
    }

    public function test_scan_creates_pending_suggestions_but_no_features(): void
    {
        $this->scan([$this->candidate('invoice', 'Invoices'), $this->candidate('customer', 'Customers')])
            ->assertCreated()
            ->assertJsonPath('data.candidates', 2)
            ->assertJsonPath('data.new', 2)
            ->assertJsonPath('data.pending_review', 2);

        $this->assertDatabaseCount('features', 0);
        $this->getJson("/api/v1/projects/{$this->project->id}/suggestions")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Customers')
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.0.source', 'repository_detected')
            ->assertJsonPath('data.0.evidence.0.type', 'route');
    }

    public function test_rescanning_updates_instead_of_duplicating(): void
    {
        $this->scan([$this->candidate('invoice', 'Invoices')])->assertCreated();
        $this->scan([
            $this->candidate('Invoices', 'Invoices', [['type' => 'model', 'path' => 'app/Models/Invoice.php']]),
            $this->candidate('invoice', 'Invoices', [['type' => 'test', 'path' => 'tests/InvoiceTest.php']]),
        ])->assertCreated()->assertJsonPath('data.new', 0)->assertJsonPath('data.updated', 1);

        $suggestion = FeatureSuggestion::sole();
        $this->assertSame('invoice', $suggestion->key);
        $this->assertEqualsCanonicalizing(['model', 'test'], array_column($suggestion->evidence, 'type'));
    }

    public function test_dismissed_suggestions_stay_dismissed_on_rescan(): void
    {
        $this->scan([$this->candidate('invoice', 'Invoices')]);
        $suggestion = FeatureSuggestion::sole();
        $this->postJson("/api/v1/suggestions/{$suggestion->id}/dismiss")->assertOk()->assertJsonPath('data.status', 'dismissed');

        $this->scan([$this->candidate('invoice', 'Invoices (renamed by scanner)')])->assertCreated();

        $suggestion->refresh();
        $this->assertSame(SuggestionStatus::Dismissed, $suggestion->status);
        $this->assertSame('Invoices', $suggestion->name);
        $this->getJson("/api/v1/projects/{$this->project->id}/suggestions")->assertJsonCount(0, 'data');
    }

    public function test_existing_feature_with_same_name_is_flagged_not_merged(): void
    {
        $feature = Feature::factory()->for($this->project)->create(['name' => 'Invoices']);

        $this->scan([$this->candidate('invoice', 'Invoice management')])->assertCreated();

        $suggestion = FeatureSuggestion::sole();
        $this->assertSame(SuggestionStatus::Pending, $suggestion->status);
        $this->assertSame($feature->id, $suggestion->matched_feature_id);
        $this->assertNull($suggestion->feature_id);
        $this->getJson("/api/v1/suggestions/{$suggestion->id}")->assertJsonPath('data.matched_feature.id', $feature->id);
    }

    public function test_accept_creates_a_feature_that_still_needs_verification(): void
    {
        $category = FeatureCategory::factory()->for($this->project)->create();
        $this->scan([$this->candidate('invoice', 'Invoices')]);
        $suggestion = FeatureSuggestion::sole();

        $featureId = $this->postJson("/api/v1/suggestions/{$suggestion->id}/accept", [
            'name' => 'Invoice list',
            'description' => 'Staff can see all invoices.',
            'category_id' => $category->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Invoice list')
            ->assertJsonPath('data.status', 'implemented')
            ->assertJsonPath('data.verification_status', 'needs_verification')
            ->assertJsonPath('data.category.id', $category->id)
            ->json('data.id');

        $suggestion->refresh();
        $this->assertSame(SuggestionStatus::Accepted, $suggestion->status);
        $this->assertSame($featureId, $suggestion->feature_id);
        $this->assertSame($this->user->id, $suggestion->reviewed_by);
        $this->assertDatabaseHas('feature_status_history', [
            'feature_id' => $featureId, 'field' => 'status', 'source' => 'agent_reported', 'note' => 'Accepted from a repository scan suggestion.',
        ]);
    }

    public function test_accept_with_chosen_status(): void
    {
        $this->scan([$this->candidate('export', 'Exports')]);
        $suggestion = FeatureSuggestion::sole();

        $this->postJson("/api/v1/suggestions/{$suggestion->id}/accept", ['status' => 'in_progress'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Exports')
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.verification_status', 'not_checked');
    }

    public function test_merge_links_to_existing_feature_without_changing_it(): void
    {
        $feature = Feature::factory()->for($this->project)->withStatus(FeatureStatus::Implemented, VerificationStatus::Verified)->create();
        $this->scan([$this->candidate('invoice', 'Invoices')]);
        $suggestion = FeatureSuggestion::sole();

        $this->postJson("/api/v1/suggestions/{$suggestion->id}/merge", ['feature_id' => $feature->id])
            ->assertOk()
            ->assertJsonPath('data.status', 'merged')
            ->assertJsonPath('data.feature.id', $feature->id);

        $this->assertSame(VerificationStatus::Verified, $feature->fresh()->verification_status);
        $this->assertDatabaseCount('features', 1);

        // Later scans keep it merged and refresh the evidence.
        $this->scan([$this->candidate('invoice', 'Invoices', [['type' => 'controller', 'path' => 'app/Http/Controllers/InvoiceController.php']])]);
        $this->assertSame(SuggestionStatus::Merged, $suggestion->fresh()->status);
        $this->assertSame('controller', $suggestion->fresh()->evidence[0]['type']);
    }

    public function test_cannot_merge_into_another_projects_feature(): void
    {
        $foreign = Feature::factory()->create();
        $this->scan([$this->candidate('invoice', 'Invoices')]);
        $suggestion = FeatureSuggestion::sole();

        $this->postJson("/api/v1/suggestions/{$suggestion->id}/merge", ['feature_id' => $foreign->id])
            ->assertUnprocessable()->assertJsonValidationErrors('feature_id');
    }

    public function test_reviewed_suggestions_cannot_be_reviewed_again(): void
    {
        $this->scan([$this->candidate('invoice', 'Invoices')]);
        $suggestion = FeatureSuggestion::sole();
        $this->postJson("/api/v1/suggestions/{$suggestion->id}/accept")->assertCreated();

        $this->postJson("/api/v1/suggestions/{$suggestion->id}/accept")->assertUnprocessable();
        $this->postJson("/api/v1/suggestions/{$suggestion->id}/dismiss")->assertUnprocessable();
        $this->postJson("/api/v1/suggestions/{$suggestion->id}/restore")->assertUnprocessable();
        $this->assertDatabaseCount('features', 1);
    }

    public function test_dismissed_suggestion_can_be_restored(): void
    {
        $this->scan([$this->candidate('invoice', 'Invoices')]);
        $suggestion = FeatureSuggestion::sole();
        $this->postJson("/api/v1/suggestions/{$suggestion->id}/dismiss")->assertOk();

        $this->postJson("/api/v1/suggestions/{$suggestion->id}/restore")->assertOk()->assertJsonPath('data.status', 'pending');
        $this->getJson("/api/v1/projects/{$this->project->id}/suggestions?status=dismissed")->assertJsonCount(0, 'data');
    }

    public function test_scan_payload_validation(): void
    {
        $this->scan([
            ['key' => '', 'name' => 'x', 'evidence' => [['type' => 'route', 'path' => 'a']]],
            ['key' => 'a', 'name' => 'A', 'evidence' => []],
            ['key' => 'b', 'name' => 'B', 'evidence' => [['type' => 'spaceship', 'path' => 'a']]],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'candidates.0.key', 'candidates.1.evidence', 'candidates.2.evidence.0.type',
        ]);

        $this->postJson("/api/v1/projects/{$this->project->id}/scans", [])->assertUnprocessable()->assertJsonValidationErrors('candidates');
        $this->assertDatabaseCount('repository_scans', 0);
    }

    public function test_empty_scan_is_recorded(): void
    {
        $this->scan([])->assertCreated()->assertJsonPath('data.candidates', 0);
        $this->getJson("/api/v1/projects/{$this->project->id}/scans")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_dashboard_counts_pending_suggestions(): void
    {
        $this->scan([$this->candidate('a', 'A'), $this->candidate('b', 'B')]);

        $this->getJson("/api/v1/projects/{$this->project->id}/dashboard")->assertJsonPath('data.summary.pending_suggestions', 2);
    }

    public function test_cli_token_can_scan_but_not_review(): void
    {
        $this->app['auth']->forgetGuards();
        $token = $this->user->createToken('cli', ['read', 'report'])->plainTextToken;

        $this->withToken($token)->postJson("/api/v1/projects/{$this->project->id}/scans", ['candidates' => [$this->candidate('a', 'A')]])->assertCreated();
        $suggestion = FeatureSuggestion::sole();

        $this->withToken($token)->postJson("/api/v1/suggestions/{$suggestion->id}/accept")->assertForbidden();
        $this->withToken($token)->postJson("/api/v1/suggestions/{$suggestion->id}/dismiss")->assertForbidden();
        $this->assertDatabaseCount('features', 0);
    }

    public function test_outsiders_cannot_scan_or_review(): void
    {
        $other = Project::factory()->create();
        $suggestion = FeatureSuggestion::factory()->for($other)->create();

        $this->postJson("/api/v1/projects/{$other->id}/scans", ['candidates' => []])->assertForbidden();
        $this->getJson("/api/v1/projects/{$other->id}/suggestions")->assertForbidden();
        $this->getJson("/api/v1/suggestions/{$suggestion->id}")->assertForbidden();
        $this->postJson("/api/v1/suggestions/{$suggestion->id}/accept")->assertForbidden();
        $this->postJson("/api/v1/suggestions/{$suggestion->id}/dismiss")->assertForbidden();
    }

    public function test_doc_requirements_carry_status_and_category_into_the_accepted_feature(): void
    {
        $doc = fn (string $key, string $name, ?string $status, string $category) => [
            ...$this->candidate($key, $name, [['type' => 'doc', 'path' => 'docs/MATRIX.md', 'detail' => 'CHK-01 · status: Partial', 'line' => 12]]),
            'category' => $category, 'status' => $status,
        ];

        $this->scan([
            $doc('req-chk-01', 'Customers shall pay by card', 'in_progress', 'Checkout'),
            $doc('req-chk-02', 'Customers shall apply discount codes', 'blocked', 'checkout'),
            $doc('req-chk-03', 'Gift cards', null, 'Checkout'),
        ])->assertCreated();

        $partial = FeatureSuggestion::where('key', 'req-chk-01')->sole();
        $this->assertSame('in_progress', $partial->suggested_status->value);
        $this->getJson("/api/v1/suggestions/{$partial->id}")->assertJsonPath('data.suggested_status', 'in_progress');

        // Accepting uses the doc's status (not "implemented") and its section as the category.
        $first = $this->postJson("/api/v1/suggestions/{$partial->id}/accept")->assertCreated()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.verification_status', 'not_checked')
            ->assertJsonPath('data.category.name', 'Checkout');

        $blocked = FeatureSuggestion::where('key', 'req-chk-02')->sole();
        $this->postJson("/api/v1/suggestions/{$blocked->id}/accept")->assertCreated()
            ->assertJsonPath('data.status', 'blocked')
            ->assertJsonPath('data.category.id', $first->json('data.category.id')); // same category, matched case-insensitively
        $this->assertSame(1, $this->project->featureCategories()->count());

        // No doc status: code exists, so implemented (which still needs verification). Edits still win.
        $plain = FeatureSuggestion::where('key', 'req-chk-03')->sole();
        $this->postJson("/api/v1/suggestions/{$plain->id}/accept", ['status' => 'planned', 'category_id' => null])->assertCreated()
            ->assertJsonPath('data.status', 'planned')
            ->assertJsonPath('data.category', null);

        $this->scan([$doc('req-chk-04', 'Wallets', 'verified', 'Checkout')])
            ->assertUnprocessable()->assertJsonValidationErrors('candidates.0.status'); // docs can't claim verification
    }
}
