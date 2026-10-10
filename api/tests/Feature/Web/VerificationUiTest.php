<?php

namespace Tests\Feature\Web;

use App\Enums\ClaimSource;
use App\Enums\FeatureStatus;
use App\Enums\VerificationStatus;
use App\Livewire\FeatureDetail;
use App\Models\Feature;
use App\Models\FeatureCheck;
use App\Models\Project;
use App\Models\User;
use App\Services\VerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class VerificationUiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->project = Project::factory()->for($this->user, 'owner')->create();
        $this->actingAs($this->user);
    }

    public function test_feature_page_lists_checks_without_editing(): void
    {
        $feature = Feature::factory()->for($this->project)->create();

        Livewire::test(FeatureDetail::class, ['feature' => $feature])->assertSee('No automated checks');

        FeatureCheck::factory()->for($feature)->create(['pattern' => 'Tests\\Feature\\InvoiceTest']);
        Livewire::test(FeatureDetail::class, ['feature' => $feature])
            ->assertSee('Tests\\Feature\\InvoiceTest')
            ->assertSee('Not run yet')
            ->assertDontSee('Add check')
            ->assertDontSee('Remove');
    }

    public function test_failures_show_on_feature_page_overview_and_run_page(): void
    {
        $feature = Feature::factory()->for($this->project)->withStatus(FeatureStatus::Implemented, VerificationStatus::NeedsVerification)->create(['name' => 'Invoices']);
        $feature->checks()->create(['pattern' => 'Tests\\Feature\\InvoiceTest']);
        Feature::factory()->for($this->project)->withStatus(FeatureStatus::Implemented, VerificationStatus::NeedsVerification)->create(['name' => 'Exports']);

        $run = app(VerificationService::class)->record($this->project, [
            'git_commit' => 'abcdef1234',
            'cases' => [['id' => 'Tests\\Feature\\InvoiceTest::test_total', 'outcome' => 'failed', 'message' => 'Expected 10, got 9']],
        ], $this->user);

        $this->get("/features/{$feature->id}")->assertOk()->assertSee('Failed')->assertSee('Expected 10, got 9');
        $this->get("/projects/{$this->project->id}")
            ->assertOk()
            ->assertSee('Latest test run')
            ->assertSee('1 implemented feature without automated checks')
            ->assertSee('Exports');
        $this->get("/test-runs/{$run->id}")->assertOk()->assertSee('Invoices')->assertSee('Expected 10, got 9');
        $this->assertDatabaseHas('feature_status_history', ['feature_id' => $feature->id, 'source' => ClaimSource::TestVerified->value, 'to_value' => 'failed']);
    }

    public function test_outsiders_cannot_see_test_runs(): void
    {
        $other = Project::factory()->create();
        $run = app(VerificationService::class)->record($other, ['cases' => [['id' => 'x', 'outcome' => 'passed']]], null);

        $this->get("/test-runs/{$run->id}")->assertForbidden();
    }
}
