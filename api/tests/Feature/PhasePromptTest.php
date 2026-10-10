<?php

namespace Tests\Feature;

use App\Enums\ClaimSource;
use App\Enums\FeatureStatus;
use App\Enums\VerificationStatus;
use App\Models\Feature;
use App\Models\Project;
use App\Services\RoadmapService;
use App\Support\PhasePrompt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhasePromptTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = Project::factory()->create(['name' => 'Shop']);
    }

    private function phases(array $phases)
    {
        return app(RoadmapService::class)->sync($this->project, $phases, ClaimSource::AgentReported);
    }

    public function test_action_follows_the_phase_state(): void
    {
        $phases = $this->phases([
            ['key' => 'P1', 'name' => 'Not started', 'items' => [['text' => 'A']]],
            ['key' => 'P2', 'name' => 'Half done', 'items' => [['text' => 'A', 'done' => true], ['text' => 'B']]],
            ['key' => 'P3', 'name' => 'Stuck', 'status' => 'blocked', 'items' => [['text' => 'A']]],
            ['key' => 'P4', 'name' => 'Finished', 'items' => [['text' => 'A', 'done' => true]]],
        ]);

        $this->assertSame(
            [['start', 'Start this phase'], ['continue', 'Continue this phase'], ['unblock', 'Unblock this phase'], ['close', 'Close out this phase']],
            $phases->map(fn ($p) => [PhasePrompt::build($p)['action'], PhasePrompt::build($p)['label']])->all(),
        );
        $this->assertStringContainsString('Find out what blocks this phase', PhasePrompt::build($phases[2])['prompt']);
        $this->assertStringContainsString('The plan says this phase is done', PhasePrompt::build($phases[3])['prompt']);
    }

    public function test_prompt_lists_open_items_and_done_but_unverified_features(): void
    {
        $cards = Feature::factory()->for($this->project)->withStatus(FeatureStatus::Implemented, VerificationStatus::Failed)->create(['name' => 'Card payments']);
        $email = Feature::factory()->for($this->project)->withStatus(FeatureStatus::InProgress)->create(['name' => 'Order email']);
        $phase = $this->phases([[
            'key' => 'MVP', 'name' => 'Launch', 'description' => 'Buy without an account', 'target_date' => '2020-01-01',
            'items' => [
                ['text' => 'Pay by card', 'done' => true, 'feature_id' => $cards->id],
                ['text' => 'Send order email', 'feature_id' => $email->id],
                ['text' => 'Gift wrap'],
            ],
        ]])->first();

        $prompt = PhasePrompt::build($phase)['prompt'];

        $this->assertStringStartsWith('Continue the roadmap phase MVP "Launch" in the Unseen project "Shop"', $prompt);
        $this->assertStringContainsString('Status: in progress, 1 of 3 items done (33%), 0 of 2 linked features verified, target date 2020-01-01 (overdue).', $prompt);
        $this->assertStringContainsString('Goal: Buy without an account', $prompt);
        $this->assertStringContainsString("- Send order email (feature #{$email->id} \"Order email\": in progress, not checked)", $prompt);
        $this->assertStringContainsString('- Gift wrap (no feature linked)', $prompt);
        $this->assertStringContainsString("Ticked done, but the linked feature isn't verified yet:\n- Pay by card (feature #{$cards->id} \"Card payments\": implemented, failed)", $prompt);
        $this->assertStringContainsString("Also cover the ticked items whose features aren't verified", $prompt);
        $this->assertStringContainsString('unseen roadmap sync --file', $prompt);
        $this->assertStringContainsString('Never mark anything verified', $prompt);
        $this->assertStringNotContainsString('&quot;', $prompt);
    }

    public function test_roadmap_page_offers_the_current_phase_and_one_prompt_per_phase(): void
    {
        $this->phases([
            ['key' => 'P1', 'name' => 'Foundation', 'items' => [['text' => 'A', 'done' => true]]],
            ['key' => 'P2', 'name' => 'Reports', 'items' => [['text' => 'B']]],
        ]);
        $this->actingAs($this->project->owner);

        $response = $this->get("/projects/{$this->project->id}/roadmap")->assertOk()
            ->assertSee('Next step for your coding agent')
            ->assertSee('Start this phase: <span class="phase-key">P2</span> Reports', false)
            ->assertSee('Close out the roadmap phase P1', false)
            ->assertSee('Start the roadmap phase P2', false);
        $this->assertSame(2, substr_count($response->getContent(), 'class="btn sm copy-phase"'));
    }
}
