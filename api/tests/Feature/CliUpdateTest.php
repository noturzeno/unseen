<?php

namespace Tests\Feature;

use App\Livewire\ProjectOverview;
use App\Models\AgentRun;
use App\Models\Project;
use App\Models\User;
use App\Services\AgentRunService;
use App\Support\Cli;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class CliUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_latest_matches_the_cli_package(): void
    {
        $package = json_decode(file_get_contents(base_path('../packages/cli/package.json')), true);

        $this->assertSame($package['version'], Cli::LATEST, 'Bump Cli::LATEST when you repack the CLI.');
    }

    public function test_api_requests_record_the_cli_version(): void
    {
        $project = Project::factory()->create();
        Sanctum::actingAs($project->owner, ['*']);

        $this->withHeader('User-Agent', 'unseen-cli/0.2.0')->getJson("/api/v1/projects/{$project->id}")->assertOk();
        $this->assertSame('0.2.0', $project->owner->fresh()->cli_version);

        $this->withHeader('User-Agent', 'Mozilla/5.0')->getJson("/api/v1/projects/{$project->id}")->assertOk();
        $this->assertSame('0.2.0', $project->owner->fresh()->cli_version, 'other clients are ignored');
    }

    public function test_dashboard_asks_people_with_an_old_cli_to_update(): void
    {
        $project = Project::factory()->create();
        $project->owner->forceFill(['cli_version' => '0.2.0', 'cli_seen_at' => now()])->save();
        $member = User::factory()->create(['name' => 'Kai']);
        $member->forceFill(['cli_version' => '0.1.0', 'cli_seen_at' => now()->subDay()])->save();
        $current = User::factory()->create(['name' => 'Current']);
        $current->forceFill(['cli_version' => Cli::LATEST, 'cli_seen_at' => now()])->save();
        $stale = User::factory()->create(['name' => 'Gone']);
        $stale->forceFill(['cli_version' => '0.1.0', 'cli_seen_at' => now()->subDays(60)])->save();
        $project->members()->attach([$member->id, $current->id, $stale->id]);

        $this->assertSame([$project->owner->id, $member->id], Cli::outdated($project, $project->owner)->pluck('id')->all());

        $this->actingAs($project->owner);
        Livewire::test(ProjectOverview::class, ['project' => $project])
            ->assertSee('Update the Unseen CLI')
            ->assertSee('Kai')
            ->assertDontSee('Gone')
            ->assertSee(Cli::installCommand());

        app(AgentRunService::class)->queue($project, $project->owner, 'update_cli');
        $this->assertStringContainsString(Cli::installCommand(), AgentRun::sole()->prompt);
    }

    public function test_no_card_when_everyone_is_up_to_date(): void
    {
        $project = Project::factory()->create();
        $project->owner->forceFill(['cli_version' => Cli::LATEST, 'cli_seen_at' => now()])->save();
        $this->actingAs($project->owner);

        Livewire::test(ProjectOverview::class, ['project' => $project])->assertDontSee('Update the Unseen CLI');
    }
}
