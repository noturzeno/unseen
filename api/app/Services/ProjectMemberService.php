<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectInvitation;
use App\Models\User;
use App\Notifications\ProjectInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Adds and removes project members. Existing users are added straight away; anyone else gets an
 * invitation link to create an account and join (there is still no open self sign-up).
 */
class ProjectMemberService
{
    /**
     * @return array{user: User}|array{invitation: ProjectInvitation, url: string}
     */
    public function addOrInvite(Project $project, string $email, User $inviter): array
    {
        $email = Str::lower(trim($email));
        $user = User::whereRaw('lower(email) = ?', [$email])->first();

        return $user ? ['user' => $this->add($project, $user)] : $this->invite($project, $email, $inviter);
    }

    public function add(Project $project, User $user): User
    {
        if ($project->isOwnedBy($user)) {
            throw ValidationException::withMessages(['email' => ['That user owns this project.']]);
        }
        if ($project->hasMember($user)) {
            throw ValidationException::withMessages(['email' => ['That user is already a member.']]);
        }

        $project->members()->attach($user);
        $project->touch();

        return $user;
    }

    /**
     * Creates (or renews, with a new link) the invitation and emails the link. The link is also returned
     * so the owner can share it directly; only its hash is stored.
     *
     * @return array{invitation: ProjectInvitation, url: string}
     */
    public function invite(Project $project, string $email, User $inviter): array
    {
        $token = Str::random(48);
        $invitation = $project->invitations()->updateOrCreate(['email' => Str::lower(trim($email))], [
            'token_hash' => hash('sha256', $token),
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(ProjectInvitation::EXPIRES_AFTER_DAYS),
            'accepted_at' => null,
        ]);
        $url = route('invitations.accept', $token);

        try {
            Notification::route('mail', $invitation->email)->notify(new ProjectInvitationNotification($project, $inviter, $url));
        } catch (\Throwable $e) {
            report($e); // mail isn't set up: the owner can still share the link
        }

        return ['invitation' => $invitation, 'url' => $url];
    }

    /** The invited person signed in (or just created their account) with the invited email. */
    public function accept(ProjectInvitation $invitation, User $user): void
    {
        DB::transaction(function () use ($invitation, $user) {
            $project = $invitation->project;
            if (! $project->isOwnedBy($user) && ! $project->hasMember($user)) {
                $project->members()->attach($user);
                $project->touch();
            }
            $invitation->update(['accepted_at' => now()]);
        });
    }

    public function remove(Project $project, User $user): void
    {
        $project->members()->detach($user);
        $project->touch();
    }
}
