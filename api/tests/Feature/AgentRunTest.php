<?php

namespace Tests\Feature;

use App\Enums\FeatureStatus;
use App\Livewire\FeatureDetail;
use App\Livewire\ProceedButton;
use App\Livewire\ProjectForm;
use App\Models\AgentRun;
use App\Models\Feature;
use App\Models\FeatureDiagram;
use App\Models\Project;
use App\Models\User;
use App\Services\AgentRunService;
use App\Support\AgentSetup;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AgentRunTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    private Feature $feature;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['name' => 'Zeno']);
        $this->project = Project::factory()->for($this->user, 'owner')->create();
        $this->project->workspaceFor($this->user)->fill(['local_path' => '/Users/zeno/code/shop'])->save();
        $this->feature = Feature::factory()->for($this->project)->withStatus(FeatureStatus::InProgress)->create(['name' => 'Wallets']);
    }

    private function runnerToken(?Project $project = null, ?User $user = null): string
    {
        return AgentSetup::issue($project ?? $this->project, $user ?? $this->user)['token'];
    }

    private function folder(?User $user = null): ?string
    {
        return $this->project->fresh()->workspaceFor($user ?? $this->user)->local_path;
    }

    public function test_proceed_queues_a_server_built_prompt_that_the_runner_claims_once(): void
    {
        $this->actingAs($this->user);
        Livewire::test(ProceedButton::class, ['project' => $this->project, 'kind' => 'feature', 'ref' => $this->feature->id])
            ->call('proceed')
            ->assertSee('Sending to your computer');

        $run = AgentRun::sole();
        $this->assertSame(AgentRun::QUEUED, $run->status);
        $this->assertSame('Continue building it: Wallets', $run->title);
        $this->assertStringStartsWith('Continue building the Unseen feature "Wallets"', $run->prompt);

        $this->app['auth']->forgetGuards();
        $token = $this->runnerToken();
        $this->withToken($token)->postJson("/api/v1/projects/{$this->project->id}/runs/claim", ['runner' => 'zeno-mac'])
            ->assertOk()
            ->assertJsonPath('data.id', $run->id)
            ->assertJsonPath('data.local_path', '/Users/zeno/code/shop')
            ->assertJsonPath('data.requested_by', 'Zeno')
            ->assertJsonPath('data.prompt', $run->prompt);
        $this->withToken($token)->postJson("/api/v1/projects/{$this->project->id}/runs/claim", ['runner' => 'zeno-mac'])->assertNoContent();

        $workspace = $this->project->fresh()->workspaceFor($this->user);
        $this->assertTrue($workspace->runnerOnline());
        $this->assertSame('zeno-mac', $workspace->runner_name);

        $this->withToken($token)->patchJson("/api/v1/runs/{$run->id}", ['status' => 'launched'])->assertOk();
        $this->assertSame(AgentRun::LAUNCHED, $run->fresh()->status);
        $this->withToken($token)->patchJson("/api/v1/runs/{$run->id}", ['status' => 'failed'])->assertStatus(409);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->user);
        Livewire::test(ProceedButton::class, ['project' => $this->project, 'kind' => 'feature', 'ref' => $this->feature->id, 'runId' => $run->id])
            ->assertSee('Opened in Claude Code')
            ->assertSee('Proceed again');
    }

    public function test_old_requests_expire_instead_of_launching_late(): void
    {
        $runs = app(AgentRunService::class);
        $old = $runs->queue($this->project, $this->user, 'feature', $this->feature->id);
        $old->forceFill(['created_at' => now()->subMinutes(AgentRun::EXPIRES_AFTER_MINUTES + 1)])->save();

        $this->withToken($this->runnerToken())->postJson("/api/v1/projects/{$this->project->id}/runs/claim", ['runner' => 'mac'])->assertNoContent();
        $this->assertSame(AgentRun::EXPIRED, $old->fresh()->status);
    }

    public function test_prompts_are_built_from_the_projects_own_records_only(): void
    {
        $foreign = Feature::factory()->create();
        $runs = app(AgentRunService::class);

        $this->expectException(ModelNotFoundException::class);
        $runs->queue($this->project, $this->user, 'feature', $foreign->id);
    }

    public function test_diagram_sync_queues_a_prompt_to_check_the_diagrams_against_the_code(): void
    {
        $diagram = FeatureDiagram::factory()->for($this->feature)->create(['title' => 'Checkout flow']);
        $this->actingAs($this->user);

        Livewire::test(FeatureDetail::class, ['feature' => $this->feature])
            ->assertSee('Copy sync prompt')
            ->assertSee('Full screen')
            ->assertSee('Exit full screen')
            ->assertSee('aria-label="Zoom in"', false)
            ->assertSee('aria-label="Zoom out"', false)
            ->assertSee('Sync with code')
            ->assertSee('Sync the diagrams of the Unseen feature &quot;Wallets&quot;', false);

        Livewire::test(ProceedButton::class, ['project' => $this->project, 'kind' => 'diagrams', 'ref' => $this->feature->id, 'label' => 'Sync with code'])
            ->assertSee('Sync with code')
            ->call('proceed')
            ->assertSee('Sending to your computer');

        $run = AgentRun::sole();
        $this->assertSame('Sync with code: Wallets diagrams', $run->title);
        $this->assertStringStartsWith('Sync the diagrams of the Unseen feature "Wallets"', $run->prompt);
        $this->assertStringContainsString("#{$diagram->id} flowchart \"Checkout flow\"", $run->prompt);
        $this->assertStringContainsString("unseen api GET /features/{$this->feature->id}/diagrams", $run->prompt);
        $this->assertStringContainsString("don't change code", $run->prompt);
    }

    public function test_without_diagrams_the_agent_is_asked_to_draw_them_from_code(): void
    {
        $this->actingAs($this->user);
        Livewire::test(FeatureDetail::class, ['feature' => $this->feature])->assertSee('Draw from code');

        $run = app(AgentRunService::class)->queue($this->project, $this->user, 'diagrams', $this->feature->id);
        $this->assertStringStartsWith('Draw diagrams for the Unseen feature "Wallets"', $run->prompt);
        $this->assertStringContainsString("unseen api POST /features/{$this->feature->id}/diagrams", $run->prompt);
    }

    public function test_diagram_sync_only_targets_the_projects_own_features(): void
    {
        $foreign = Feature::factory()->create();

        $this->expectException(ModelNotFoundException::class);
        app(AgentRunService::class)->queue($this->project, $this->user, 'diagrams', $foreign->id);
    }

    public function test_unknown_kinds_and_outsiders_are_refused(): void
    {
        $this->actingAs($this->user);
        Livewire::test(ProceedButton::class, ['project' => $this->project, 'kind' => 'shell', 'ref' => null])
            ->call('proceed')->assertStatus(422);

        $this->actingAs(User::factory()->create());
        Livewire::test(ProceedButton::class, ['project' => $this->project, 'kind' => 'feature', 'ref' => $this->feature->id])
            ->call('proceed')->assertForbidden();

        $other = Project::factory()->for($this->user, 'owner')->create();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->runnerToken($other))->postJson("/api/v1/projects/{$this->project->id}/runs/claim", ['runner' => 'mac'])->assertForbidden();
        $this->assertSame(0, AgentRun::count());
    }

    public function test_without_a_project_folder_proceed_still_queues_and_the_runner_fills_in_its_folder(): void
    {
        $this->project->workspaces()->delete();
        $this->actingAs($this->user);

        Livewire::test(ProceedButton::class, ['project' => $this->project, 'kind' => 'feature', 'ref' => $this->feature->id])
            ->assertDontSee('Proceed…')
            ->call('proceed')
            ->assertSee('Sending to your computer', false);
        $this->assertSame(1, AgentRun::count());

        $this->app['auth']->forgetGuards();
        $token = $this->runnerToken();
        $this->withToken($token)->postJson("/api/v1/projects/{$this->project->id}/runs/claim", ['runner' => 'mac', 'folder' => 'relative/x'])
            ->assertUnprocessable();
        $this->withToken($token)->postJson("/api/v1/projects/{$this->project->id}/runs/claim", ['runner' => 'mac', 'folder' => '/Users/dev/code/app'])
            ->assertOk()->assertJsonPath('data.local_path', '/Users/dev/code/app');
        $this->assertSame('/Users/dev/code/app', $this->folder());

        // A folder set in Settings is never overwritten by a runner.
        $this->withToken($token)->postJson("/api/v1/projects/{$this->project->id}/runs/claim", ['runner' => 'mac', 'folder' => '/elsewhere'])
            ->assertNoContent();
        $this->assertSame('/Users/dev/code/app', $this->folder());
    }

    public function test_project_folder_is_set_in_settings_and_validated(): void
    {
        $this->actingAs($this->user);
        $form = Livewire::test(ProjectForm::class, ['project' => $this->project]);

        foreach (['relative/path', "/tmp/x\nrm -rf /"] as $bad) {
            $form->set('local_path', $bad)->call('save')->assertHasErrors('local_path');
        }
        foreach (['/Users/zeno/code/sgc', '~/code/sgc', 'C:\\code\\sgc', ''] as $good) {
            $form->set('local_path', $good)->call('save')->assertHasNoErrors();
        }
        $form->set('local_path', '/Users/zeno/code/sgc')->call('save');
        $this->assertSame('/Users/zeno/code/sgc', $this->folder());

        // The API can't change it: an agent must not redirect where the runner opens Claude Code.
        $this->app['auth']->forgetGuards();
        $full = $this->user->createToken('full')->plainTextToken;
        $this->withToken($full)->patchJson("/api/v1/projects/{$this->project->id}", ['local_path' => '/tmp/evil'])->assertOk();
        $this->assertSame('/Users/zeno/code/sgc', $this->folder());
    }

    public function test_each_member_has_their_own_folder_and_only_their_own_runner_opens_their_clicks(): void
    {
        $dev = User::factory()->create(['name' => 'Dev']);
        $this->project->members()->attach($dev);

        // The contributor sets their own folder; the owner's is untouched, and each sees their own.
        $this->actingAs($dev);
        Livewire::test(ProjectForm::class, ['project' => $this->project])
            ->assertSet('local_path', '')
            ->set('local_path', '/home/dev/client-shop')->call('save')->assertHasNoErrors();
        $this->assertSame('/home/dev/client-shop', $this->folder($dev));
        $this->assertSame('/Users/zeno/code/shop', $this->folder());
        $this->actingAs($this->user);
        Livewire::test(ProjectForm::class, ['project' => $this->project])->assertSet('local_path', '/Users/zeno/code/shop');

        // The contributor clicks Proceed: the owner's runner doesn't take it, the contributor's does.
        $this->actingAs($dev);
        Livewire::test(ProceedButton::class, ['project' => $this->project, 'kind' => 'feature', 'ref' => $this->feature->id])->call('proceed');
        $run = AgentRun::sole();

        $this->app['auth']->forgetGuards();
        $this->withToken($this->runnerToken())->postJson("/api/v1/projects/{$this->project->id}/runs/claim", ['runner' => 'zeno-mac'])
            ->assertNoContent();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->runnerToken(user: $dev))->postJson("/api/v1/projects/{$this->project->id}/runs/claim", ['runner' => 'dev-pc'])
            ->assertOk()
            ->assertJsonPath('data.id', $run->id)
            ->assertJsonPath('data.local_path', '/home/dev/client-shop')
            ->assertJsonPath('data.requested_by', 'Dev');

        $this->assertSame('dev-pc', $this->project->fresh()->workspaceFor($dev)->runner_name);
        $this->assertSame('zeno-mac', $this->project->fresh()->workspaceFor($this->user)->runner_name);
    }

    public function test_start_runner_button_shows_while_your_runner_is_offline(): void
    {
        $this->actingAs($this->user);
        $link = 'unseen://runner?folder=%2FUsers%2Fzeno%2Fcode%2Fshop';

        Livewire::test(ProjectForm::class, ['project' => $this->project])->assertSee($link, false)->assertSee('Start runner');

        $this->project->workspaceFor($this->user)->fill(['runner_seen_at' => now(), 'runner_name' => 'zeno-mac'])->save();
        Livewire::test(ProjectForm::class, ['project' => $this->project])->assertDontSee($link, false)->assertSee('Your runner is online');

        // A stale Proceed offers it too.
        $run = app(AgentRunService::class)->queue($this->project, $this->user, 'feature', $this->feature->id);
        $run->forceFill(['created_at' => now()->subMinute()])->save();
        Livewire::test(ProceedButton::class, ['project' => $this->project, 'kind' => 'feature', 'ref' => $this->feature->id, 'runId' => $run->id])
            ->assertSee($link, false);
    }
}
