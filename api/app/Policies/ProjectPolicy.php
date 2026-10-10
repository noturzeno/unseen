<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

/**
 * Owners have full control. Members (project_members) can view and edit
 * project details but cannot archive/unarchive the project or manage members.
 */
class ProjectPolicy
{
    /**
     * A token limited to one project can't see or touch any other, or create projects.
     */
    public function before(User $user, string $ability, mixed $project = null): ?bool
    {
        $only = $user->tokenProjectId();
        if ($only === null) {
            return null;
        }
        if ($ability === 'create' || ($project instanceof Project && $project->id !== $only)) {
            return false;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Project $project): bool
    {
        return $project->isOwnedBy($user) || $project->hasMember($user);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Project $project): bool
    {
        return $this->view($user, $project);
    }

    public function archive(User $user, Project $project): bool
    {
        return $project->isOwnedBy($user);
    }

    public function manageMembers(User $user, Project $project): bool
    {
        return $project->isOwnedBy($user);
    }
}
