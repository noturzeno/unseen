<div>
    <div class="page-head">
        <div>
            @if ($project)
                <a href="{{ route('projects.show', $project) }}" wire:navigate class="back">{{ $project->name }}</a>
            @else
                <a href="{{ route('projects.index') }}" wire:navigate class="back">Projects</a>
            @endif
            <h1>{{ $project ? 'Project settings' : 'New project' }}</h1>
        </div>
    </div>

    @if ($project) <x-project-tabs :project="$project" /> @endif

    @if ($project)
        <livewire:connect-agent :project="$project" />
    @endif

    <form class="card" wire:submit="save">
        <div class="field">
            <label for="name">Name</label>
            <input id="name" type="text" wire:model="name" required>
            @error('name') <div class="error">{{ $message }}</div> @enderror
        </div>
        <div class="field">
            <label for="description">Description</label>
            <textarea id="description" wire:model="description"></textarea>
            @error('description') <div class="error">{{ $message }}</div> @enderror
        </div>
        <div class="field">
            <label for="repository_url">Repository</label>
            <input id="repository_url" type="text" wire:model="repository_url" placeholder="git@github.com:org/repo.git">
            <div class="hint">A repository URL or an absolute local path.</div>
            @error('repository_url') <div class="error">{{ $message }}</div> @enderror
        </div>
        <div class="field">
            <label for="tech_stack">Main technology</label>
            <input id="tech_stack" type="text" wire:model="tech_stack" placeholder="Laravel, Vue">
            @error('tech_stack') <div class="error">{{ $message }}</div> @enderror
        </div>
        <fieldset class="field">
            <legend>Platforms</legend>
            <div class="checks">
                @foreach (\App\Enums\Platform::cases() as $platform)
                    <label><input type="checkbox" wire:model="platforms" value="{{ $platform->value }}"> {{ $platform->label() }}</label>
                @endforeach
            </div>
            <div class="hint">With more than one, the dashboard shows progress per platform. Coding agents tag each feature with the platforms it is on; untagged features count on every platform.</div>
            @error('platforms') <div class="error">{{ $message }}</div> @enderror
            @error('platforms.*') <div class="error">{{ $message }}</div> @enderror
        </fieldset>
        <div class="field">
            <label><input type="checkbox" wire:model="mismatches_enabled"> Track PRD vs design</label>
            <div class="hint">Coding agents record where the build differs from the PRD, for you or the client to decide. Turn it off for projects without a separate design: the tab, the dashboard card and the agent's instructions for it go away. Mismatches already recorded are kept and come back if you turn it on again.</div>
            @error('mismatches_enabled') <div class="error">{{ $message }}</div> @enderror
        </div>
        <div class="field">
            <label><input type="checkbox" wire:model="actions_minutes_out"> Out of GitHub Actions minutes</label>
            <div class="hint">Tick this when GitHub stops CI jobs because the account's Actions minutes or spending limit ran out. The dashboard then shows everyone how to run CI on a local Mac, so test runs keep verifying features. Each person can dismiss it; untick it once CI runs again.</div>
            @error('actions_minutes_out') <div class="error">{{ $message }}</div> @enderror
        </div>
        @if ($project)
            @php($workspace = $project->workspaceFor(auth()->user()))
            <div class="field" id="project-folder">
                <label for="local_path">Your project folder on your computer</label>
                <input id="local_path" type="text" wire:model="local_path" placeholder="/Users/you/code/client-portal" spellcheck="false" autocomplete="off">
                <div class="hint">
                    Just for you: every member sets their own. Your <strong>Proceed</strong> clicks go to the
                    <code>unseen runner</code> signed in with your token, which opens Claude Code in this folder. The folder
                    must already be linked to this project (it has <code>.unseen.json</code>, from the setup prompt). Leave it
                    empty and it is filled in when you start the runner inside the linked repository.
                </div>
                @error('local_path') <div class="error">{{ $message }}</div> @enderror
                <div class="runner-status small" @unless ($workspace->runnerOnline()) wire:poll.5s @endunless>
                    @if ($workspace->runnerOnline())
                        <span class="badge ok">Your runner is online</span> {{ $workspace->runner_name }} · checked in {{ $workspace->runner_seen_at->diffForHumans() }}
                    @else
                        @if ($workspace->runner_seen_at)
                            <span class="badge">Your runner is offline</span> last seen {{ $workspace->runner_seen_at->diffForHumans() }} on {{ $workspace->runner_name }}
                        @else
                            <span class="badge">No runner yet</span>
                        @endif
                        <a class="btn sm" href="{{ $workspace->startRunnerUrl() }}" title="Opens a Terminal window running unseen runner on this computer">Start runner</a>
                    @endif
                    <details class="help">
                        <summary>How the runner works</summary>
                        <p class="muted"><strong>Start runner</strong> opens a Terminal window running the runner; keep it open (Ctrl+C stops it). It only opens Claude Code in folders linked to this project, and Claude Code still asks before it runs commands.</p>
                        <p class="muted">First time on this computer, the button does nothing yet: start the runner once in a terminal. That also sets up the button for next time.</p>
                        <pre><code>cd {{ $workspace->local_path ?: '/path/to/the/project' }}
unseen runner</code></pre>
                    </details>
                </div>
            </div>
        @endif
        <button class="btn primary" type="submit">Save project</button>
    </form>

    @if ($project)
        @php($isOwner = $project->isOwnedBy(auth()->user()))
        <div class="card">
            <h2>People</h2>
            <p class="muted small">Members (contributors) can view and edit the project and its features, and connect their own coding agent. Only the owner can archive it or manage members.</p>
            <ul class="plain">
                <li class="small">{{ $project->owner->name }} <span class="muted">{{ $project->owner->email }}</span> <span class="badge">Owner</span></li>
                @foreach ($project->members()->orderBy('name')->get() as $member)
                    <li class="small" wire:key="m-{{ $member->id }}">
                        {{ $member->name }} <span class="muted">{{ $member->email }}</span>
                        @if ($member->is(auth()->user()))
                            · <button class="btn link danger" type="button" wire:click="removeMember({{ $member->id }})" wire:confirm="Leave this project?">Leave project</button>
                        @elseif ($isOwner)
                            · <button class="btn link danger" type="button" wire:click="removeMember({{ $member->id }})" wire:confirm="Remove {{ $member->name }} from this project?">Remove</button>
                        @endif
                    </li>
                @endforeach
            </ul>
            @if ($isOwner)
                @php($pending = $project->invitations()->whereNull('accepted_at')->orderBy('email')->get())
                @if ($pending->isNotEmpty())
                    <h3 class="small">Invited</h3>
                    <ul class="plain">
                        @foreach ($pending as $invitation)
                            <li class="small" wire:key="inv-{{ $invitation->id }}">
                                {{ $invitation->email }}
                                <span class="muted">
                                    @if ($invitation->expires_at->isPast()) · link expired @else · link expires {{ $invitation->expires_at->diffForHumans() }} @endif
                                </span>
                                · <button class="btn link" type="button" wire:click="renewInvitation({{ $invitation->id }})">New link</button>
                                · <button class="btn link danger" type="button" wire:click="revokeInvitation({{ $invitation->id }})" wire:confirm="Cancel the invitation for {{ $invitation->email }}?">Cancel</button>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <form class="inline-form" wire:submit="addMember">
                    <input type="email" wire:model="memberEmail" placeholder="contributor@example.com" aria-label="Email of the person to add or invite">
                    <button class="btn" type="submit">Add or invite</button>
                </form>
                @error('memberEmail') <div class="error">{{ $message }}</div> @enderror
                @if ($inviteUrl)
                    <div class="invite-link" x-data="{ copied: false }">
                        <p class="small">Invitation emailed. You can also send this link yourself. It works once and expires in {{ \App\Models\ProjectInvitation::EXPIRES_AFTER_DAYS }} days:</p>
                        <input type="text" x-ref="link" readonly value="{{ $inviteUrl }}" aria-label="Invitation link" x-on:focus="$el.select()">
                        <button class="btn" type="button"
                            x-on:click="navigator.clipboard?.writeText($refs.link.value).then(() => { copied = true; setTimeout(() => copied = false, 2500) }, () => $refs.link.select())">
                            <span x-show="! copied">Copy link</span><span x-show="copied" x-cloak>Copied ✓</span>
                        </button>
                    </div>
                @endif
                <div class="hint">People with an account are added straight away. Anyone else gets an invitation to create one. Each contributor then uses <strong>Connect your coding agent</strong> on the project to get their own token, so their agent's features, reports and test runs land here under their name.</div>
            @endif
        </div>
    @endif

    @if ($project && $project->isOwnedBy(auth()->user()))
        <div class="card">
            <h2>{{ $project->isArchived() ? 'Restore project' : 'Archive project' }}</h2>
            <p class="muted small">Archived projects are hidden from the project list. Nothing is deleted.</p>
            <button class="btn {{ $project->isArchived() ? '' : 'danger' }}" wire:click="toggleArchive">
                {{ $project->isArchived() ? 'Restore project' : 'Archive project' }}
            </button>
        </div>
    @endif
</div>
