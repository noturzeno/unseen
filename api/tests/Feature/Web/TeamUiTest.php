<?php

namespace Tests\Feature\Web;

use App\Enums\FeatureStatus;
use App\Livewire\ProjectForm;
use App\Livewire\ProjectOverview;
use App\Livewire\TokenSettings;
use App\Livewire\UnresolvedIndex;
use App\Models\Feature;
use App\Models\FeatureCategory;
use App\Models\Project;
use App\Models\UnresolvedItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Livewire;
use Tests\TestCase;

class TeamUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_manages_members_on_settings_page(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for($owner, 'owner')->create();
        $colleague = User::factory()->create(['name' => 'Chris Colleague']);
        $this->actingAs($owner);

        Livewire::test(ProjectForm::class, ['project' => $project])
            ->set('memberEmail', 'not-an-email')
            ->call('addMember')
            ->assertHasErrors('memberEmail')
            ->set('memberEmail', $owner->email)
            ->call('addMember')
            ->assertHasErrors('memberEmail')
            ->set('memberEmail', $colleague->email)
            ->call('addMember')
            ->assertHasNoErrors()
            ->assertSee('Chris Colleague')
            ->call('removeMember', $colleague->id);

        $this->assertFalse($project->hasMember($colleague));
    }

    public function test_member_can_leave_but_not_manage(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();
        $project = Project::factory()->create();
        $project->members()->attach([$member->id, $other->id]);
        $this->actingAs($member);

        Livewire::test(ProjectForm::class, ['project' => $project])
            ->assertDontSee('Add member')
            ->call('addMember')
            ->assertForbidden();
        Livewire::test(ProjectForm::class, ['project' => $project])->call('removeMember', $other->id)->assertForbidden();
        $this->assertTrue($project->hasMember($other));

        Livewire::test(ProjectForm::class, ['project' => $project])
            ->call('removeMember', $member->id)
            ->assertRedirect(route('projects.index'));
        $this->assertFalse($project->hasMember($member));
    }

    public function test_token_settings_create_shows_value_once_and_revoke(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create()->createToken('theirs');
        $this->actingAs($user);
        $this->get('/settings/tokens')->assertOk();

        $component = Livewire::test(TokenSettings::class)
            ->set('name', 'Claude on laptop')
            ->set('preset', 'agent')
            ->set('expiresInDays', '30')
            ->call('create')
            ->assertHasNoErrors();

        $token = $user->tokens()->sole();
        $this->assertSame(['read', 'report', 'record'], $token->abilities);
        $this->assertTrue($token->expires_at->between(now()->addDays(29), now()->addDays(31)));
        $this->assertStringContainsString('|', $component->get('newToken'));

        $component->call('dismissNewToken')->assertSet('newToken', null)->call('revoke', $other->accessToken->id);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $other->accessToken->id]);

        $component->call('revoke', $token->id);
        $this->assertSame(0, $user->tokens()->count());

        Livewire::test(TokenSettings::class)->set('name', 'x')->set('preset', 'superuser')->call('create')->assertHasErrors('preset');
    }

    public function test_unresolved_items_in_overview_and_list_page(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user, 'owner')->create();
        $a = UnresolvedItem::factory()->for($project)->create(['text' => 'Add rate limiting']);
        $b = UnresolvedItem::factory()->for($project)->create(['text' => 'Write docs']);
        $foreign = UnresolvedItem::factory()->create();
        $this->actingAs($user);

        $this->get("/projects/{$project->id}")->assertSeeHtml('Unresolved work <span class="count">2</span>')->assertSee('Add rate limiting');

        $a->forceFill(['status' => UnresolvedItem::RESOLVED, 'resolved_at' => now(), 'resolution_note' => 'Docs merged'])->save();
        $foreign->forceFill(['text' => 'Someone else\'s work'])->save();

        Livewire::test(UnresolvedIndex::class, ['project' => $project])
            ->assertSee('Write docs')
            ->assertDontSee('Add rate limiting')
            ->assertDontSee('Mark done')
            ->set('status', 'resolved')
            ->assertSee('Add rate limiting')
            ->assertSee('Docs merged')
            ->assertDontSee('Reopen')
            ->assertDontSee("Someone else's work");
    }

    public function test_web_pages_cannot_change_tracked_data(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user, 'owner')->create();
        $category = FeatureCategory::factory()->for($project)->create();
        $item = UnresolvedItem::factory()->for($project)->create();
        $this->actingAs($user);

        // Agents own this data; the old editing actions are gone, not just hidden.
        foreach ([
            [ProjectOverview::class, 'resolveItem', $item->id],
            [ProjectOverview::class, 'deleteCategory', $category->id],
            [ProjectOverview::class, 'addCategory', null],
            [UnresolvedIndex::class, 'resolve', $item->id],
        ] as [$component, $method, $arg]) {
            try {
                Livewire::test($component, ['project' => $project])->call($method, $arg);
                $this->fail("{$component}::{$method} should not exist");
            } catch (MethodNotFoundException) {
                // expected
            }
        }

        $this->assertModelExists($category);
        $this->assertSame(UnresolvedItem::OPEN, $item->fresh()->status);
        $this->get("/projects/{$project->id}/features/create")->assertNotFound();
    }

    public function test_new_pages_forbid_outsiders(): void
    {
        $project = Project::factory()->create();
        $this->actingAs(User::factory()->create());

        $this->get("/projects/{$project->id}/unresolved")->assertForbidden();
        $this->get("/projects/{$project->id}/edit")->assertForbidden();
    }

    public function test_features_can_be_searched_and_quick_filtered(): void
    {
        $project = Project::factory()->create();
        $this->actingAs($project->owner);
        Feature::factory()->for($project)->withStatus(FeatureStatus::Implemented)->create(['name' => 'Invoice export']);
        Feature::factory()->for($project)->create(['name' => 'Invoice email']);
        Feature::factory()->for($project)->create(['name' => '100% discount']);
        Feature::factory()->for($project)->create(['name' => 'Login']);

        Livewire::test(ProjectOverview::class, ['project' => $project])
            ->set('search', 'invoice')
            ->assertSee('Invoice export')->assertSee('Invoice email')->assertDontSee('Login')
            ->set('status', 'implemented')
            ->assertSee('Invoice export')->assertDontSee('Invoice email')
            ->set('search', '%')  // wildcard is matched literally
            ->set('status', '')
            ->assertSee('100% discount')->assertDontSee('Login')
            ->call('clearFilters')
            ->assertSet('search', '')
            ->assertSee('Login');
    }
}
