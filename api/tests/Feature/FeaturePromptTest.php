<?php

namespace Tests\Feature;

use App\Enums\ClaimSource;
use App\Enums\FeatureStatus;
use App\Enums\VerificationStatus;
use App\Models\Feature;
use App\Models\FeatureCheck;
use App\Models\Project;
use App\Models\TestRun;
use App\Models\User;
use App\Services\RoadmapService;
use App\Support\FeaturePrompt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeaturePromptTest extends TestCase
{
    use RefreshDatabase;

    private function feature(FeatureStatus $status, VerificationStatus $verification = VerificationStatus::NotChecked): Feature
    {
        $project = Project::factory()->create(['name' => 'Shop']);

        return Feature::factory()->for($project)->withStatus($status, $verification)->create([
            'name' => 'Card payments',
            'description' => 'Customers pay by card at checkout.',
        ]);
    }

    public function test_the_next_step_follows_the_features_state(): void
    {
        foreach ([
            [FeatureStatus::Planned, VerificationStatus::NotChecked, 'build', 'Start building it', 'Set it in progress'],
            [FeatureStatus::InProgress, VerificationStatus::NotChecked, 'continue', 'Continue building it', 'build what is missing'],
            [FeatureStatus::Blocked, VerificationStatus::NotChecked, 'unblock', 'Unblock it', 'Find out what blocks it'],
            [FeatureStatus::Implemented, VerificationStatus::NeedsVerification, 'verify', 'Get it verified', 'tests that prove every acceptance criterion'],
            [FeatureStatus::Implemented, VerificationStatus::Failed, 'fix', 'Fix the failing checks', 'no linked tests'],
            [FeatureStatus::Implemented, VerificationStatus::Verified, 'extend', 'Change or extend it', 'It is verified'],
            [FeatureStatus::NotImplemented, VerificationStatus::NotChecked, 'revisit', 'Revisit it', 'back in scope'],
        ] as [$status, $verification, $action, $label, $text]) {
            $next = FeaturePrompt::build($this->feature($status, $verification));
            $this->assertSame($action, $next['action'], $status->value);
            $this->assertSame($label, $next['label']);
            $this->assertStringContainsString($text, $next['prompt'], $action);
        }
    }

    public function test_prompt_carries_criteria_checks_with_failures_and_roadmap_items(): void
    {
        $feature = $this->feature(FeatureStatus::Implemented, VerificationStatus::Failed);
        $feature->acceptanceCriteria()->create(['description' => 'A declined card shows an error', 'position' => 0, 'source' => 'agent_reported']);
        $check = FeatureCheck::factory()->for($feature)->create(['pattern' => 'Tests\\Feature\\CardPaymentTest']);
        $run = TestRun::factory()->for($feature->project)->create();
        $check->results()->create([
            'test_run_id' => $run->id, 'feature_id' => $feature->id, 'outcome' => 'failed', 'matched' => 2, 'failed' => 1,
            'failures' => [['id' => 'CardPaymentTest::test_declined', 'message' => 'Expected 402, got 500']],
        ]);
        app(RoadmapService::class)->sync($feature->project, [
            ['key' => 'MVP', 'name' => 'Launch', 'items' => [['text' => 'Card payments', 'done' => true, 'feature_id' => $feature->id]]],
        ], ClaimSource::AgentReported);

        $prompt = FeaturePrompt::build($feature->fresh())['prompt'];

        $this->assertStringStartsWith('Fix the Unseen feature "Card payments" (feature #'.$feature->id.' in project "Shop").', $prompt);
        $this->assertStringContainsString("- A declined card shows an error\n", $prompt);
        $this->assertStringContainsString('- Tests\\Feature\\CardPaymentTest: FAILED', $prompt);
        $this->assertStringContainsString('  - CardPaymentTest::test_declined: Expected 402, got 500', $prompt);
        $this->assertStringContainsString('- MVP Launch: "Card payments" (ticked done)', $prompt);
        $this->assertStringContainsString('Fix the code so the failing checks above pass again', $prompt);
        $this->assertStringContainsString("unseen api PATCH /features/{$feature->id}", $prompt);
        $this->assertStringContainsString('Never mark it verified', $prompt);
        $this->assertStringNotContainsString('&quot;', $prompt);
        $this->assertDoesNotMatchRegularExpression('/\d+\|[A-Za-z0-9]{20,}/', $prompt); // never a token
    }

    public function test_feature_without_criteria_is_asked_to_write_them_first(): void
    {
        $prompt = FeaturePrompt::build($this->feature(FeatureStatus::Planned))['prompt'];

        $this->assertStringContainsString('It has no acceptance criteria yet. Write them first', $prompt);
        $this->assertStringContainsString('No tests are linked to it yet', $prompt);
    }

    public function test_feature_page_offers_the_prompt(): void
    {
        $feature = $this->feature(FeatureStatus::InProgress);
        $owner = User::find($feature->project->owner_id);

        $this->actingAs($owner)->get("/features/{$feature->id}")
            ->assertOk()
            ->assertSee('Next step for your coding agent')
            ->assertSee('Continue building it')
            ->assertSee('Copy prompt')
            ->assertSee('Continue building the Unseen feature', false);
    }
}
