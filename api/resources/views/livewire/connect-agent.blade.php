<div @class(['card', 'connect-agent', 'intro' => $intro && ! $prompt])>
    @if ($prompt)
        <div class="card-head">
            <h2>Paste this into your coding agent <span class="muted small">· {{ ['new' => 'just started', 'existing' => 'already developed', 'join' => 'join this project'][$stage] }}</span></h2>
            <span class="badge warn">Contains a secret · shown once</span>
        </div>
        <ol class="steps small">
            <li>Open the real project's repository in Claude Code (or another coding agent).</li>
            <li>Copy the prompt below and paste it as a message. The agent installs the CLI, links the repository, saves the token outside the repo, and {{ ['new' => 'records the planned features, then tracks them as it builds', 'existing' => 'records every existing feature from the code', 'join' => 'reads the features already recorded, without importing them again'][$stage] }}.</li>
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
        @if ($featuresCount > 0)
            <div class="stage-choice">
                <button type="button" class="stage" wire:click="generate('join')" wire:loading.attr="disabled">
                    <strong>Join this project</strong>
                    <span>{{ $featuresCount }} {{ Str::plural('feature', $featuresCount) }} already recorded. Links your agent to the existing inventory without scanning or importing it again.</span>
                    <em>Get join prompt →</em>
                </button>
            </div>
            <details class="help">
                <summary class="muted small">Set up from scratch instead</summary>
                <p class="muted small">Only for a repository whose features aren't in Unseen yet. The agent checks what's recorded first, but a full setup can still add duplicates.</p>
                <x-agent-stages />
            </details>
        @else
            <x-agent-stages />
        @endif
    @endif
</div>
