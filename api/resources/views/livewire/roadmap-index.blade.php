<div>
    <div class="page-head">
        <div>
            <a href="{{ route('projects.show', $project) }}" wire:navigate class="back">{{ $project->name }}</a>
            <h1>Roadmap</h1>
            <p class="muted">The project's own plan, phase by phase, kept in sync by its coding agent.
                @if ($project->roadmap_synced_at) Last synced {{ $project->roadmap_synced_at->diffForHumans() }}. @endif
            </p>
        </div>
    </div>

    <x-project-tabs :project="$project" />

    @if ($current)
        @php($next = $prompts[$current->id])
        <div class="card next-step" x-data="{ copied: false, open: false }">
            <div class="next-row">
                <span class="connect-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 17l6-6-6-6M12 19h8"/></svg>
                </span>
                <div class="next-text">
                    <span class="muted small">Next step for your coding agent</span>
                    <strong>{{ $next['label'] }}: <span class="phase-key">{{ $current->key }}</span> {{ $current->name }}</strong>
                </div>
                <button class="btn ghost" type="button" x-on:click="open = ! open" x-text="open ? 'Hide prompt' : 'Preview'">Preview</button>
                <button class="btn primary" type="button"
                    x-on:click="navigator.clipboard?.writeText($refs.phasePrompt.value).then(() => { copied = true; setTimeout(() => copied = false, 2500) }, () => { open = true; $nextTick(() => $refs.phasePrompt.select()) })">
                    <span x-show="! copied">Copy prompt</span><span x-show="copied" x-cloak>Copied ✓</span>
                </button>
                <livewire:proceed-button :project="$project" kind="phase" :ref="$current->id" :key="'proceed-phase-top-'.$current->id" />
            </div>
            <div x-show="open" x-cloak>
                <textarea class="prompt" x-ref="phasePrompt" readonly aria-label="Prompt for your coding agent" x-on:focus="$el.select()">{{ $next['prompt'] }}</textarea>
                <p class="hint">Paste it into Claude Code in this project's repository. It contains no token. Each phase below has its own prompt too.</p>
            </div>
        </div>
    @endif

    @if ($phases->isEmpty())
        <div class="card empty">
            <h2>No roadmap yet</h2>
            <p class="muted">The coding agent syncs the project's phases and implementation lists with <code>unseen roadmap</code>.</p>
        </div>
    @else
        <ol class="roadmap">
            @foreach ($phases as $phase)
                @php($p = \App\Services\RoadmapService::progress($phase))
                @php($overdue = $phase->target_date && $phase->target_date->isPast() && $phase->status !== \App\Enums\PhaseStatus::Done)
                <li class="phase {{ $phase->status->value }}" wire:key="phase-{{ $phase->id }}">
                    <span class="phase-dot" aria-hidden="true"></span>
                    <div class="card">
                        <div class="phase-head">
                            <div>
                                <div class="phase-meta small">
                                    <span class="phase-key">{{ $phase->key }}</span>
                                    <span class="badge {{ $phase->status->tone() }}">{{ $phase->status->label() }}</span>
                                    @if ($phase->target_date)
                                        <span @class(['muted' => ! $overdue, 'overdue' => $overdue])>
                                            {{ $overdue ? 'Overdue · ' : 'Target ' }}{{ $phase->target_date->format('j M Y') }}
                                        </span>
                                    @endif
                                </div>
                                <h2>{{ $phase->name }}</h2>
                                @if ($phase->description) <p class="muted small">{{ $phase->description }}</p> @endif
                            </div>
                            <div class="phase-progress">
                                @if ($p['percent'] !== null)
                                    <strong>{{ $p['percent'] }}%</strong>
                                    <span class="muted small">{{ $p['done'] }} of {{ $p['items'] }} done</span>
                                @endif
                                <button class="btn sm copy-phase" type="button" x-data="{ copied: false }" data-prompt="{{ $prompts[$phase->id]['prompt'] }}"
                                    title="{{ $prompts[$phase->id]['label'] }}: copy a prompt for your coding agent"
                                    x-on:click="navigator.clipboard?.writeText($el.dataset.prompt).then(() => { copied = true; setTimeout(() => copied = false, 2500) })">
                                    <span x-show="! copied">Copy prompt</span><span x-show="copied" x-cloak>Copied ✓</span>
                                </button>
                                <livewire:proceed-button :project="$project" kind="phase" :ref="$phase->id" :small="true" :key="'proceed-phase-'.$phase->id" />
                            </div>
                        </div>

                        @if ($p['items'])
                            <div class="progress"><div class="bar"><div @class(['fill', 'ok' => $p['percent'] === 100]) style="width: {{ $p['percent'] }}%"></div></div></div>
                        @endif
                        @if ($p['features'])
                            <p class="small muted">
                                {{ $p['features'] }} linked {{ Str::plural('feature', $p['features']) }}:
                                {{ $p['implemented'] }} implemented, <span @class(['ok-text' => $p['verified'] === $p['features']])>{{ $p['verified'] }} verified</span>.
                                @if ($phase->status === \App\Enums\PhaseStatus::Done && $p['verified'] < $p['features'])
                                    <span class="badge warn">Done, not all verified</span>
                                @endif
                            </p>
                        @endif

                        @if ($phase->items->isNotEmpty())
                            <ul class="checklist">
                                @foreach ($phase->items as $item)
                                    <li @class(['done' => $item->done]) wire:key="item-{{ $item->id }}">
                                        <span class="check" aria-label="{{ $item->done ? 'Done' : 'Not done' }}"></span>
                                        <span class="text">{{ $item->text }}</span>
                                        @if ($item->feature)
                                            <a class="feature-link" href="{{ route('features.show', $item->feature) }}" wire:navigate>{{ $item->feature->name }}</a>
                                            <x-status-badge :status="$item->feature->verification_status" />
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    @endif
</div>
