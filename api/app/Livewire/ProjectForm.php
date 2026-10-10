<?php

namespace App\Livewire;

use App\Http\Requests\StoreProjectRequest;
use App\Models\Project;
use App\Services\ProjectMemberService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Create and edit a project. Archive/unarchive is owner-only.
 */
class ProjectForm extends Component
{
    public ?Project $project = null;

    public string $name = '';

    public string $description = '';

    public string $repository_url = '';

    public string $tech_stack = '';

    /** @var list<string> Platform values the project ships on. */
    public array $platforms = [];

    /** PRD vs design: off hides the tab, the dashboard card and the agent's instructions for it. */
    public bool $mismatches_enabled = true;

    /** Where the repository is on the signed-in person's computer; their `unseen runner` opens Claude Code there. */
    public string $local_path = '';

    public string $memberEmail = '';

    /** Invitation link just created; shown once to the owner so they can share it. */
    public ?string $inviteUrl = null;

    public function mount(?Project $project = null): void
    {
        if ($project?->exists) {
            Gate::authorize('update', $project);
            $this->project = $project;
            $this->fill(array_map(fn ($v) => (string) $v, $project->only(['name', 'description', 'repository_url', 'tech_stack'])));
            $this->platforms = $project->platforms ?? [];
            $this->mismatches_enabled = $project->mismatches_enabled;
            $this->local_path = (string) $project->workspaceFor(auth()->user())->local_path;
        } else {
            Gate::authorize('create', Project::class);
            $this->project = null;
        }
    }

    public function save()
    {
        $request = new StoreProjectRequest;
        $data = collect($this->validate([
            ...$request->rules(),
            // An absolute folder path (macOS/Linux, ~/…, or Windows). Web only: the API can't change it.
            'local_path' => ['nullable', 'string', 'max:1024', 'regex:#^(/|~/|[A-Za-z]:[\\\\/])[^\r\n\x00]*$#'],
        ], [...$request->messages(), 'local_path.regex' => 'Use the full path of the folder, e.g. /Users/you/code/client-portal.'], ['local_path' => 'project folder']))
            ->map(fn ($v) => $v === '' ? null : $v)
            ->all();

        $localPath = $data['local_path'] ?? null;
        unset($data['local_path']);

        if ($this->project) {
            Gate::authorize('update', $this->project);
            $this->project->update($data);
            $project = $this->project;
        } else {
            Gate::authorize('create', Project::class);
            $project = auth()->user()->ownedProjects()->create($data);
        }
        if ($this->project) { // each person's own folder; other members' are untouched
            $project->workspaceFor(auth()->user())->fill(['local_path' => $localPath])->save();
        }

        session()->flash('status', 'Project saved.');

        return $this->redirectRoute('projects.show', $project, navigate: true);
    }

    public function toggleArchive()
    {
        Gate::authorize('archive', $this->project);

        $this->project->forceFill(['archived_at' => $this->project->isArchived() ? null : now()])->save();

        session()->flash('status', $this->project->isArchived() ? 'Project archived.' : 'Project restored.');

        return $this->redirectRoute('projects.show', $this->project, navigate: true);
    }

    public function addMember(ProjectMemberService $members): void
    {
        Gate::authorize('manageMembers', $this->project);
        $this->validate(['memberEmail' => ['required', 'email', 'max:255']], [], ['memberEmail' => 'email']);

        try {
            $result = $members->addOrInvite($this->project, $this->memberEmail, auth()->user());
        } catch (ValidationException $e) {
            $this->addError('memberEmail', $e->errors()['email'][0] ?? 'Could not add that user.');

            return;
        }

        $this->reset('memberEmail');
        if (isset($result['user'])) {
            $this->inviteUrl = null;
            session()->flash('status', "{$result['user']->name} can now view and edit this project.");
        } else {
            $this->inviteUrl = $result['url'];
        }
    }

    /** A new link for a pending invitation (the old link stops working). */
    public function renewInvitation(int $id, ProjectMemberService $members): void
    {
        Gate::authorize('manageMembers', $this->project);
        $invitation = $this->project->invitations()->whereNull('accepted_at')->findOrFail($id);

        $this->inviteUrl = $members->invite($this->project, $invitation->email, auth()->user())['url'];
    }

    public function revokeInvitation(int $id): void
    {
        Gate::authorize('manageMembers', $this->project);
        $this->project->invitations()->whereNull('accepted_at')->whereKey($id)->delete();
        $this->inviteUrl = null;
    }

    public function removeMember(int $userId, ProjectMemberService $members)
    {
        $user = $this->project->members()->findOrFail($userId);
        $leaving = $user->is(auth()->user());

        if (! $leaving) {
            Gate::authorize('manageMembers', $this->project);
        }

        $members->remove($this->project, $user);

        if ($leaving) {
            session()->flash('status', "You left {$this->project->name}.");

            return $this->redirectRoute('projects.index', navigate: true);
        }
    }

    public function render()
    {
        return view('livewire.project-form')
            ->title($this->project ? 'Edit '.$this->project->name : 'New project');
    }
}
