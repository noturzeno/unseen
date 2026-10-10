<?php

namespace App\Livewire;

use App\Models\ProjectInvitation;
use App\Models\User;
use App\Services\ProjectMemberService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Opened from an invitation link: the invited person creates their account (or signs in with the
 * invited email) and joins the project as a member.
 */
#[Title('Join project')]
class AcceptInvitation extends Component
{
    #[Locked]
    public string $token = '';

    public string $name = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token, ProjectMemberService $members)
    {
        $this->token = $token;
        $invitation = $this->invitation();

        if ($invitation && Auth::check() && $this->isInvitedUser(Auth::user(), $invitation)) {
            return $this->join($invitation, $members);
        }
        if ($invitation && ! Auth::check() && $this->existingUser($invitation)) {
            session()->put('url.intended', route('invitations.accept', $token)); // back here after signing in
        }
    }

    public function register(ProjectMemberService $members)
    {
        $invitation = $this->invitation();
        abort_unless($invitation && ! Auth::check() && ! $this->existingUser($invitation), 403);

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = DB::transaction(function () use ($invitation, $members) {
            $user = User::create(['name' => $this->name, 'email' => $invitation->email, 'password' => $this->password]);
            $user->forceFill(['email_verified_at' => now()])->save(); // they received the link at this address
            $members->accept($invitation, $user);

            return $user;
        });

        Auth::login($user);
        session()->regenerate();
        session()->flash('status', "Welcome! You can now see {$invitation->project->name}. Connect your coding agent to start tracking your work.");

        return $this->redirectRoute('projects.show', $invitation->project, navigate: true);
    }

    private function join(ProjectInvitation $invitation, ProjectMemberService $members)
    {
        $members->accept($invitation, Auth::user());
        session()->flash('status', "You joined {$invitation->project->name}. Connect your coding agent to start tracking your work.");

        return $this->redirectRoute('projects.show', $invitation->project, navigate: true);
    }

    private function invitation(): ?ProjectInvitation
    {
        $invitation = ProjectInvitation::findByToken($this->token);

        return $invitation?->isUsable() ? $invitation : null;
    }

    private function existingUser(ProjectInvitation $invitation): ?User
    {
        return User::whereRaw('lower(email) = ?', [Str::lower($invitation->email)])->first();
    }

    private function isInvitedUser(User $user, ProjectInvitation $invitation): bool
    {
        return Str::lower($user->email) === Str::lower($invitation->email);
    }

    public function render()
    {
        $invitation = $this->invitation();

        return view('livewire.accept-invitation', [
            'invitation' => $invitation,
            'state' => match (true) {
                ! $invitation => 'invalid',
                Auth::check() => 'wrong-account',
                (bool) $this->existingUser($invitation) => 'sign-in',
                default => 'register',
            },
        ]);
    }
}
