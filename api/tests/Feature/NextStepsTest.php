<?php

namespace Tests\Feature;

use App\Enums\ClaimSource;
use App\Enums\FeatureStatus;
use App\Enums\VerificationStatus;
use App\Models\Feature;
use App\Models\FeatureCheck;
use App\Models\FeatureSuggestion;
use App\Models\Project;
use App\Models\TestRun;
use App\Models\UnresolvedItem;
use App\Services\RoadmapService;
use App\Support\NextSteps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NextStepsTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = Project::factory()->create(['name' => 'Shop']);
    }

    private function feature(string $name, FeatureStatus $status, VerificationStatus $verification = VerificationStatus::NotChecked): Feature
    {
        return Feature::factory()->for($this->project)->withStatus($status, $verification)->create(['name' => $name]);
    }

    public function test_items_waiting_on_a_decision_are_not_handed_to_the_agent(): void
    {
        UnresolvedItem::factory()->for($this->project)->create(['text' => 'Known defect: needs client rule', 'waiting_on' => 'client']);
        UnresolvedItem::factory()->for($this->project)->create(['text' => 'Pick a map API', 'waiting_on' => 'team']);
        UnresolvedItem::factory()->for($this->project)->create(['text' => 'Polish the footer', 'waiting_on' => 'agent']);

        $steps = NextSteps::for($this->project);

        $this->assertSame(['unresolved'], $steps->pluck('kind')->all()); // no defect step, no sort step: all sorted
        $this->assertSame('Work through 1 open item', $steps[0]['title']);
        $this->assertStringContainsString('#3: Polish the footer', $steps[0]['prompt']);
        $this->assertStringContainsString('Skip these: they wait on a decision from the client or from me', $steps[0]['prompt']);
        $this->assertStringContainsString('#1 (waiting on the client)', $steps[0]['prompt']);
    }

    public function test_steps_are_ranked_broken_first_then_blocked_then_new_work(): void
    {
        $this->feature('Cards', FeatureStatus::Implemented, VerificationStatus::Failed);
        $this->feature('Wallets', FeatureStatus::Blocked);
        $this->feature('Emails', FeatureStatus::InProgress);
        $tested = $this->feature('Search', FeatureStatus::Implemented, VerificationStatus::NeedsVerification);
        FeatureCheck::factory()->for($tested)->create();
        $this->feature('Login', FeatureStatus::Implemented, VerificationStatus::NeedsVerification); // no tests
        UnresolvedItem::factory()->for($this->project)->create(['text' => 'Known defect: bad id returns 500']);
        UnresolvedItem::factory()->for($this->project)->create(['text' => 'Polish the footer']);
        FeatureSuggestion::factory()->for($this->project)->count(2)->create();
        app(RoadmapService::class)->sync($this->project, [
            ['key' => 'M1', 'name' => 'Launch', 'target_date' => '2020-01-01', 'items' => [['text' => 'A', 'done' => true], ['text' => 'B']]],
        ], ClaimSource::AgentReported);

        $steps = NextSteps::for($this->project);

        $this->assertSame(
            ['fix', 'defect', 'phase', 'unblock', 'suggestions', 'ci', 'tests', 'sort', 'continue', 'unresolved'],
            $steps->pluck('kind')->all(),
        );
        $this->assertSame([1, 1, 1, 2, 2, 2, 2, 2, 3, 3], $steps->pluck('priority')->all());
        $this->assertSame('Fix “Cards”', $steps[0]['title']);
        $this->assertStringStartsWith('Overdue since 1 Jan 2020', $steps[2]['reason']);
        $this->assertSame('Review 2 scan suggestions', $steps[4]['title']);
        $this->assertSame('Link tests to 2 implemented features', $steps[6]['title']); // Cards and Login
        $this->assertSame('Work through 1 open item', $steps[9]['title']); // the defect is listed on its own

        // Every step carries a prompt for the agent, and none holds a token.
        foreach ($steps as $step) {
            $this->assertStringContainsString('Never put secrets', $step['prompt'], $step['kind']);
            $this->assertDoesNotMatchRegularExpression('/\d+\|[A-Za-z0-9]{20,}/', $step['prompt']);
        }
        $this->assertStringContainsString('unseen api POST /suggestions/<id>/accept', $steps[4]['prompt']);
        $this->assertStringContainsString('unseen verify --run', $steps[5]['prompt']);
        $this->assertStringContainsString('- #'.Feature::where('name', 'Login')->value('id').' Login', $steps[6]['prompt']);
    }

    public function test_ci_step_disappears_once_a_test_run_exists_and_phase_is_medium_when_not_overdue(): void
    {
        $f = $this->feature('Search', FeatureStatus::Implemented, VerificationStatus::NeedsVerification);
        FeatureCheck::factory()->for($f)->create();
        TestRun::factory()->for($this->project)->create();
        app(RoadmapService::class)->sync($this->project, [['key' => 'M2', 'name' => 'Next', 'items' => [['text' => 'B']]]], ClaimSource::AgentReported);

        $steps = NextSteps::for($this->project);

        $this->assertNotContains('ci', $steps->pluck('kind')->all());
        $this->assertSame(NextSteps::MEDIUM, $steps->firstWhere('kind', 'phase')['priority']);
    }

    public function test_empty_project_has_no_steps_and_overview_shows_the_card_when_there_are(): void
    {
        $this->assertTrue(NextSteps::for($this->project)->isEmpty());

        $this->feature('Cards', FeatureStatus::Implemented, VerificationStatus::Failed);
        $this->actingAs($this->project->owner)->get("/projects/{$this->project->id}")->assertOk()
            ->assertSee('Suggested next steps')
            ->assertSee('Fix “Cards”')
            ->assertSee('High');
    }
}
