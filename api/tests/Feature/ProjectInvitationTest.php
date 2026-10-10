<?php

namespace Tests\Feature;

use App\Livewire\AcceptInvitation;
use App\Livewire\ProjectForm;
use App\Models\FeatureStatusChange;
use App\Models\Project;
use App\Models\ProjectInvitation;
use App\Models\User;
use App\Notifications\ProjectInvitationNotification;
use App\Services\ProjectMemberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectInvitationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->owner = User::factory()->create();
        $this->project = Project::factory()->for($this->owner, 'owner')->create(['name' => 'Client Portal']);
    }

    private function token(string $url): string
    {
        return basename(parse_url($url, PHP_URL_PATH));
    }

    public function test_owner_invites_someone_without_an_account_and_gets_a_link_once(): void
    {
        $this->actingAs($this->owner);
        $form = Livewire::test(ProjectForm::class, ['project' => $this->project])
            ->set('memberEmail', 'New.Dev@Example.com')->call('addMember')->assertHasNoErrors();

        $url = $form->get('inviteUrl');
        $this->assertStringContainsString('/invitations/', $url);
        $form->assertSee($url, false)->assertSee('new.dev@example.com');

        $invitation = ProjectInvitation::sole();
        $this->assertSame('new.dev@example.com', $invitation->email);
        $this->assertNotSame($this->token($url), $invitation->token_hash, 'only the hash is stored');
        Notification::assertSentTo(new AnonymousNotifiable, ProjectInvitationNotification::class,
            fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'new.dev@example.com');
        $this->assertFalse($this->project->hasMember(User::factory()->create()));
    }

    public function test_existing_users_are_still_added_straight_away(): void
    {
        $colleague = User::factory()->create(['email' => 'colleague@example.com']);
        $this->actingAs($this->owner);

        Livewire::test(ProjectForm::class, ['project' => $this->project])
            ->set('memberEmail', 'colleague@example.com')->call('addMember')->assertSet('inviteUrl', null);

        $this->assertTrue($this->project->hasMember($colleague));
        $this->assertSame(0, ProjectInvitation::count());
    }

    public function test_invited_person_creates_an_account_and_joins(): void
    {
        $this->actingAs($this->owner);
        $url = Livewire::test(ProjectForm::class, ['project' => $this->project])
            ->set('memberEmail', 'dev@example.com')->call('addMember')->get('inviteUrl');
        auth()->logout();

        $this->get($url)->assertOk()->assertSee('Join Client Portal')->assertSee('dev@example.com');

        Livewire::test(AcceptInvitation::class, ['token' => $this->token($url)])
            ->set('name', 'Dev Person')->set('password', 'short')->set('password_confirmation', 'short')
            ->call('register')->assertHasErrors('password')
            ->set('password', 'correct-horse')->set('password_confirmation', 'correct-horse')
            ->call('register')->assertRedirect(route('projects.show', $this->project));

        $dev = User::where('email', 'dev@example.com')->sole();
        $this->assertAuthenticatedAs($dev);
        $this->assertTrue($this->project->hasMember($dev));
        $this->assertNotNull(ProjectInvitation::sole()->accepted_at);

        // The link works once.
        auth()->logout();
        $this->get($url)->assertOk()->assertSee('Invitation not valid');
    }

    public function test_a_contributor_can_connect_their_own_agent_and_reports_are_theirs(): void
    {
        $dev = User::factory()->create();
        $this->project->members()->attach($dev);

        Sanctum::actingAs($dev, ['read', 'report', 'record']);
        $this->postJson("/api/v1/projects/{$this->project->id}/features", ['name' => 'Invoice download'])->assertCreated();
        $this->assertTrue(FeatureStatusChange::where('user_id', $dev->id)->exists(), 'the change is recorded under the contributor');
    }

    public function test_expired_renewed_and_cancelled_links_stop_working(): void
    {
        $this->actingAs($this->owner);
        $form = Livewire::test(ProjectForm::class, ['project' => $this->project])
            ->set('memberEmail', 'dev@example.com')->call('addMember');
        $first = $form->get('inviteUrl');
        $invitation = ProjectInvitation::sole();

        $second = $form->call('renewInvitation', $invitation->id)->get('inviteUrl');
        $this->assertNotSame($first, $second);
        $this->assertSame(1, ProjectInvitation::count());
        auth()->logout();
        $this->get($first)->assertSee('Invitation not valid');
        $this->get($second)->assertSee('Join Client Portal');

        $invitation->refresh()->update(['expires_at' => now()->subMinute()]);
        $this->get($second)->assertSee('Invitation not valid');

        $this->actingAs($this->owner);
        $form->call('revokeInvitation', $invitation->id);
        $this->assertSame(0, ProjectInvitation::count());
    }

    public function test_existing_account_signs_in_then_joins_and_other_accounts_cannot_use_the_link(): void
    {
        ['url' => $url] = app(ProjectMemberService::class)->invite($this->project, 'later@example.com', $this->owner);
        $later = User::factory()->create(['email' => 'later@example.com']);

        $this->get($url)->assertSee('Sign in');
        $this->assertSame($url, session('url.intended'));

        $this->actingAs(User::factory()->create());
        $this->get($url)->assertSee('Sign out, then open this link again');
        $this->assertFalse($this->project->hasMember($later));

        $this->actingAs($later);
        $this->get($url)->assertRedirect(route('projects.show', $this->project));
        $this->assertTrue($this->project->hasMember($later));
    }

    public function test_only_the_owner_invites_and_the_api_returns_the_link(): void
    {
        $member = User::factory()->create();
        $this->project->members()->attach($member);
        $this->actingAs($member);
        Livewire::test(ProjectForm::class, ['project' => $this->project])
            ->set('memberEmail', 'x@example.com')->call('addMember')->assertForbidden();

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->owner, ['*']);
        $this->postJson("/api/v1/projects/{$this->project->id}/members", ['email' => 'api@example.com'])
            ->assertStatus(202)
            ->assertJsonPath('data.invitation.email', 'api@example.com')
            ->assertJsonStructure(['data' => ['invitation' => ['url', 'expires_at']]]);
    }
}
