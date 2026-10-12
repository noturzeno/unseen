<?php

namespace Tests\Feature\Web;

use App\Enums\ClaimSource;
use App\Enums\FeatureStatus;
use App\Livewire\FeatureDetail;
use App\Models\Feature;
use App\Models\Project;
use App\Models\User;
use App\Services\FeatureStatusService;
use App\Services\ProjectDashboard;
use App\Support\FeaturePrompt;
use App\Support\NextSteps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class IgnoreFeatureTest extends TestCase
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

    public function test_ignoring_records_the_decision_with_its_reason(): void
    {
        $feature = Feature::factory()->for($this->project)->withStatus(FeatureStatus::NotImplemented)->create();

        Livewire::test(FeatureDetail::class, ['feature' => $feature])
            ->assertSee('Ignore')
            ->call('ignore')
            ->assertHasErrors(['ignoreReason' => 'required'])
            ->set('ignoreReason', 'Subscriptions are out of the MVP.')
            ->call('ignore')
            ->assertHasNoErrors()
            ->assertSee('Ignored: this feature won')
            ->assertSee('Subscriptions are out of the MVP.')
            ->assertSee('Bring back into scope');

        $this->assertSame(FeatureStatus::Ignored, $feature->fresh()->status);
        $change = $feature->statusHistory()->first();
        $this->assertSame(['not_implemented', 'ignored', 'Subscriptions are out of the MVP.', $this->user->id, ClaimSource::UserEntered],
            [$change->from_value, $change->to_value, $change->note, $change->user_id, $change->source]);
    }

    public function test_restoring_brings_back_the_status_it_had_before(): void
    {
        $feature = Feature::factory()->for($this->project)->withStatus(FeatureStatus::InProgress)->create();
        app(FeatureStatusService::class)->ignore($feature, $this->user, 'Not needed.');

        Livewire::test(FeatureDetail::class, ['feature' => $feature->fresh()])->call('restore')->assertSee('Continue building it');

        $this->assertSame(FeatureStatus::InProgress, $feature->fresh()->status);
    }

    public function test_people_who_cant_edit_the_project_cant_ignore(): void
    {
        $feature = Feature::factory()->for($this->project)->create();
        $this->actingAs(User::factory()->create());

        Livewire::test(FeatureDetail::class, ['feature' => $feature])->assertForbidden();
        $this->assertSame(FeatureStatus::Planned, $feature->fresh()->status);
    }

    public function test_agents_cant_set_or_lift_ignored(): void
    {
        $feature = Feature::factory()->for($this->project)->create();
        Sanctum::actingAs($this->user, ['*']);

        $this->patchJson("/api/v1/features/{$feature->id}", ['status' => 'ignored'])->assertUnprocessable()->assertJsonValidationErrors('status');

        app(FeatureStatusService::class)->ignore($feature, $this->user, 'Dropped.');
        $this->patchJson("/api/v1/features/{$feature->id}", ['status' => 'in_progress'])->assertUnprocessable()->assertJsonValidationErrors('status');

        // A report about it is kept as evidence, but the decision stands.
        $this->postJson("/api/v1/projects/{$this->project->id}/reports", [
            'summary' => 'Built subscriptions anyway',
            'features' => [['id' => $feature->id, 'status' => 'implemented']],
        ])->assertCreated();
        $this->assertSame(FeatureStatus::Ignored, $feature->fresh()->status);

        $this->expectException(ValidationException::class);
        app(FeatureStatusService::class)->apply($feature->fresh(), FeatureStatus::Planned, null, ClaimSource::AgentReported);
    }

    public function test_ignored_features_leave_progress_next_steps_and_prompts(): void
    {
        Feature::factory()->for($this->project)->withStatus(FeatureStatus::Implemented)->create();
        $ignored = Feature::factory()->for($this->project)->withStatus(FeatureStatus::Blocked)->create(['name' => 'Subscribe to Standard']);
        app(FeatureStatusService::class)->ignore($ignored, $this->user, 'Out of scope.');

        $summary = app(ProjectDashboard::class)->summary($this->project);
        $this->assertSame(1, $summary['total']);
        $this->assertSame(1, $summary['by_status']['ignored']);
        $this->assertSame(100, $summary['progress']['implemented_percent']);

        $this->assertFalse(NextSteps::for($this->project)->contains(fn ($s) => $s['ref'] === $ignored->id));

        $next = FeaturePrompt::build($ignored->fresh());
        $this->assertSame('ignored', $next['action']);
        $this->assertStringContainsString('Out of scope.', $next['prompt']);
        $this->assertStringContainsString("Don't build", $next['prompt']);
    }
}
