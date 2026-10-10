<?php

namespace Tests\Feature\Web;

use App\Enums\FeatureStatus;
use App\Livewire\Login;
use App\Livewire\ProjectForm;
use App\Livewire\ProjectOverview;
use App\Models\Feature;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WebUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_and_logout(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse-battery']);

        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'wrong')
            ->call('login')
            ->assertHasErrors('email');
        $this->assertGuest();

        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'correct-horse-battery')
            ->call('login')
            ->assertRedirect(route('projects.index'));
        $this->assertAuthenticatedAs($user);

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_login_is_rate_limited(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 5) as $i) {
            Livewire::test(Login::class)->set('email', $user->email)->set('password', 'nope')->call('login');
        }

        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'password')
            ->call('login')
            ->assertHasErrors('email');
        $this->assertGuest();
    }

    public function test_pages_render_for_owner(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user, 'owner')->create(['name' => 'Portal']);
        $feature = Feature::factory()->for($project)->withStatus(FeatureStatus::Blocked)->create(['name' => 'Checkout']);

        $this->actingAs($user);
        $this->get('/projects')->assertOk()->assertSee('Portal');
        $this->get('/projects/create')->assertOk();
        $this->get("/projects/{$project->id}")->assertOk()->assertSee('Checkout')->assertSee('Needs attention');
        $this->get("/projects/{$project->id}/edit")->assertOk();
        $this->get("/features/{$feature->id}")->assertOk()->assertSee('Checkout')->assertDontSee('Save status');
        // Features are recorded by agents through the API; there are no web forms for them.
        $this->get("/projects/{$project->id}/features/create")->assertNotFound();
        $this->get("/features/{$feature->id}/edit")->assertNotFound();
    }

    public function test_outsiders_get_403_on_every_page(): void
    {
        $project = Project::factory()->create(['name' => 'Secret project']);
        $feature = Feature::factory()->for($project)->create();

        $this->actingAs(User::factory()->create());
        $this->get('/projects')->assertOk()->assertDontSee('Secret project');
        $this->get("/projects/{$project->id}")->assertForbidden();
        $this->get("/projects/{$project->id}/edit")->assertForbidden();
        $this->get("/features/{$feature->id}")->assertForbidden();
    }

    public function test_create_edit_and_archive_project(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(ProjectForm::class)
            ->set('name', '')
            ->set('repository_url', 'not a repo')
            ->call('save')
            ->assertHasErrors(['name', 'repository_url']);

        Livewire::test(ProjectForm::class)
            ->set('name', 'Portal')
            ->set('repository_url', 'git@github.com:example/portal.git')
            ->call('save');

        $project = Project::firstWhere('name', 'Portal');
        $this->assertSame($user->id, $project->owner_id);
        $this->assertNull($project->description);

        Livewire::test(ProjectForm::class, ['project' => $project])
            ->assertSet('name', 'Portal')
            ->set('description', 'Customer portal')
            ->call('save');
        $this->assertSame('Customer portal', $project->fresh()->description);

        Livewire::test(ProjectForm::class, ['project' => $project])->call('toggleArchive');
        $this->assertTrue($project->fresh()->isArchived());
    }

    public function test_members_cannot_archive_from_the_ui(): void
    {
        $member = User::factory()->create();
        $project = Project::factory()->create();
        $project->members()->attach($member);
        $this->actingAs($member);

        Livewire::test(ProjectForm::class, ['project' => $project])
            ->assertDontSee('Archive project')
            ->call('toggleArchive')
            ->assertForbidden();
        $this->assertFalse($project->fresh()->isArchived());
    }

    public function test_overview_filters(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user, 'owner')->create();
        Feature::factory()->for($project)->withStatus(FeatureStatus::Blocked)->create(['name' => 'Alpha feature']);
        Feature::factory()->for($project)->create(['name' => 'Beta feature']);
        $this->actingAs($user);

        $names = fn (array $expected) => fn ($features) => $features->pluck('name')->sort()->values()->all() === $expected;

        Livewire::test(ProjectOverview::class, ['project' => $project])
            ->assertViewHas('features', $names(['Alpha feature', 'Beta feature']))
            ->set('status', 'planned')
            ->assertViewHas('features', $names(['Beta feature']))
            ->set('status', 'bogus') // ignored, not trusted
            ->assertViewHas('features', $names(['Alpha feature', 'Beta feature']));
    }
}
