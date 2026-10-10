<div @class(['card', 'connect-agent', 'intro' => $intro && ! $prompt])>
    @if ($prompt)
        <div class="card-head">
            <h2>Paste this into your coding agent <span class="muted small">· {{ $stage === 'new' ? 'just started' : 'already developed' }}</span></h2>
            <span class="badge warn">Contains a secret · shown once</span>
        </div>
        <ol class="steps small">
            <li>Open the real project's repository in Claude Code (or another coding agent).</li>
            <li>Copy the prompt below and paste it as a message. The agent installs the CLI, links the repository, saves the token outside the repo, and {{ $stage === 'new' ? 'records the planned features, then tracks them as it builds' : 'records every existing feature from the code' }}.</li>
            <li>Come back here: features appear as the agent records them.</li>
        </ol>
        <div x-data="{ copied: false }">
            <textarea class="prompt" x-ref="prompt" readonly aria-label="Setup prompt for your coding agent" x-on:focus="$el.select()">{{ $prompt }}</textarea>
            <div class="form-actions">
                <button class="btn primary" type="button"
                    x-on:click="navigator.clipboard?.writeText($refs.prompt.value).then(() => { copied = true; setTimeout(() => copied = false, 2500) }, () => $refs.prompt.select())">
                    <span x-show="! copied">Copy prompt</span><span x-show="copied" x-cloak>Copied ✓</span>
                </button>
                <button class="btn ghost" type="button" wire:click="dismiss">Done, hide it</button>
            </div>
        </div>
        <p class="hint">Only paste it into your coding agent. The token works for <strong>this project only</strong>, can't verify features or change members or tokens, and expires in {{ $days }} days. Revoke it any time on <a href="{{ route('settings.tokens') }}" wire:navigate>API tokens</a>.</p>
    @else
        <div class="connect-row">
            <span class="connect-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 17H7A5 5 0 0 1 7 7h2M15 7h2a5 5 0 1 1 0 10h-2M8 12h8"/></svg>
            </span>
            <div class="connect-text">
                <h2>{{ $intro ? 'Connect your coding agent' : 'Coding agent' }}</h2>
                @if ($intro)
                    <p class="muted">Unseen fills itself in: your coding agent records the features, their status, tests and diagrams as it works. Pick where the project is to get a prompt to paste into it.</p>
                @elseif ($tokens->isEmpty())
                    <p class="muted small">No agent is connected to this project yet.</p>
                @else
                    <p class="muted small">
                        {{ $tokens->count() }} agent {{ Str::plural('token', $tokens->count()) }} for this project ·
                        last used {{ $tokens->max('last_used_at')?->diffForHumans() ?? 'never' }}.
                        Each prompt has its own token for this project: get a new one for another repository or computer.
                    </p>
                @endif
            </div>
        </div>
        <div class="stage-choice" role="group" aria-label="Where is this project?">
            <button type="button" class="stage" wire:click="generate('new')" wire:loading.attr="disabled">
                <strong>Just started</strong>
                <span>Little or no code yet. The agent records the planned features, then tracks them as it builds.</span>
                <em>Get setup prompt →</em>
            </button>
            <button type="button" class="stage" wire:click="generate('existing')" wire:loading.attr="disabled">
                <strong>Already developed</strong>
                <span>Existing codebase. The agent scans it and records every feature, its status, tests and diagrams.</span>
                <em>Get setup prompt →</em>
            </button>
        </div>
    @endif
</div>
