@php
    $passing = $checks->filter(fn ($c) => $c->latestResult?->outcome === 'passed')->count();
    $failing = $checks->filter(fn ($c) => $c->latestResult?->outcome === 'failed')->count();
    $reportCount = $evidence->pluck('development_report_id')->filter()->unique()->count();
    $changeLabel = fn ($value) => str_replace('_', ' ', (string) $value);
@endphp
@assets
    {{-- Bundled (public/vendor/mermaid, MIT) so diagrams work offline; the hash pins the exact file. --}}
    <script src="{{ asset('vendor/mermaid/11.4.1/mermaid.min.js') }}" integrity="sha384-rbtjAdnIQE/aQJGEgXrVUlMibdfTSa4PQju4HDhN3sR2PmaKFzhEafuePsl9H/9I"></script>
    <script src="{{ asset('js/diagrams.js') }}?v={{ filemtime(public_path('js/diagrams.js')) }}"></script>
@endassets
<div>
    <div class="page-head">
        <div>
            <a href="{{ route('projects.show', $feature->project) }}" wire:navigate class="back">{{ $feature->project->name }}</a>
            <h1>{{ $feature->name }}</h1>
            <div class="meta">
                <x-status-badge :status="$feature->status" />
                <x-status-badge :status="$feature->verification_status" />
                @if ($feature->category) <span class="muted small">· {{ $feature->category->name }}</span> @endif
                <span class="muted small">· Updated {{ $feature->updated_at->diffForHumans() }}</span>
            </div>
        </div>
    </div>

    {{-- Next step: a prompt for the project's coding agent (no token in it; the repo is already linked) --}}
    <div class="card next-step" x-data="{ copied: false, open: false }">
        <div class="next-row">
            <span class="connect-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 17l6-6-6-6M12 19h8"/></svg>
            </span>
            <div class="next-text">
                <span class="muted small">Next step for your coding agent</span>
                <strong>{{ $next['label'] }}</strong>
            </div>
            <button class="btn ghost" type="button" x-on:click="open = ! open" x-text="open ? 'Hide prompt' : 'Preview'">Preview</button>
            <button class="btn primary" type="button"
                x-on:click="navigator.clipboard?.writeText($refs.nextPrompt.value).then(() => { copied = true; setTimeout(() => copied = false, 2500) }, () => { open = true; $nextTick(() => $refs.nextPrompt.select()) })">
                <span x-show="! copied">Copy prompt</span><span x-show="copied" x-cloak>Copied ✓</span>
            </button>
            <livewire:proceed-button :project="$feature->project" kind="feature" :ref="$feature->id" :key="'proceed-feature-'.$feature->id" />
        </div>
        <div x-show="open" x-cloak>
            <textarea class="prompt" x-ref="nextPrompt" readonly aria-label="Prompt for your coding agent" x-on:focus="$el.select()">{{ $next['prompt'] }}</textarea>
            <p class="hint">Paste it into Claude Code (or another coding agent) in this project's repository. It contains no token.</p>
        </div>
    </div>

    {{-- Lifecycle at a glance --}}
    <div class="card lifecycle-card">
        <ol class="lifecycle" aria-label="Feature lifecycle">
            @foreach ($lifecycle as $step)
                <li class="step {{ $step['state'] }}" @if (! in_array($step['state'], ['done', 'upcoming'], true)) aria-current="step" @endif>
                    <span class="dot" aria-hidden="true">
                        @if ($step['state'] === 'done')
                            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 8.5 6.5 11.5 12.5 4.5"/></svg>
                        @elseif (in_array($step['state'], ['failed', 'stopped'], true))
                            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M5 5l6 6M11 5l-6 6"/></svg>
                        @elseif ($step['state'] === 'blocked')
                            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M8 4.5v4.5M8 11.5v.01"/></svg>
                        @else
                            {{ $loop->iteration }}
                        @endif
                    </span>
                    <span class="step-text">
                        <strong>{{ $step['label'] }}</strong>
                        @if ($step['hint']) <span class="hint-text">{{ $step['hint'] }}</span> @endif
                        <span class="when">{{ $step['at'] ? $step['at']->format('j M Y') : '—' }}</span>
                    </span>
                </li>
            @endforeach
        </ol>

        <div class="facts">
            <div class="fact">
                <span class="lbl">Acceptance criteria</span>
                <span class="val">{{ $feature->acceptanceCriteria->count() }}</span>
            </div>
            <div class="fact">
                <span class="lbl">Automated checks</span>
                <span class="val">{{ $checks->count() }}
                    @if ($checks->isNotEmpty())
                        <small @class(['bad' => $failing, 'ok' => ! $failing && $passing === $checks->count()])>{{ $passing }} passing{{ $failing ? ', '.$failing.' failing' : '' }}</small>
                    @endif
                </span>
            </div>
            <div class="fact">
                <span class="lbl">Agent reports</span>
                <span class="val">{{ $reportCount }}</span>
            </div>
            <div class="fact">
                <span class="lbl">Diagrams</span>
                <span class="val">{{ $diagrams->count() }}</span>
            </div>
            <div class="fact">
                <span class="lbl">Tracked since</span>
                <span class="val date">{{ ($lifecycle[0]['at'] ?? $feature->created_at)->format('j M Y') }}</span>
            </div>
        </div>
    </div>

    @if ($mismatches->isNotEmpty())
        <div class="card mismatch-summary">
            <div class="card-head">
                <h2>PRD vs design <span class="count">{{ $mismatches->count() }}</span></h2>
                <a href="{{ route('mismatches.index', $feature->project) }}" wire:navigate>Decide on the PRD vs design page</a>
            </div>
            <div class="mismatch-list">
                @foreach ($mismatches as $m)
                    @php($m->setRelation('feature', null))
                    <x-mismatch-card :mismatch="$m" wire:key="fm-{{ $m->id }}" />
                @endforeach
            </div>
        </div>
    @endif

    <div class="dash">
        <div class="dash-main">
            <div class="card">
                <h2>What it does</h2>
                <p>{{ $feature->description ?: 'No description yet.' }}</p>

                <h3 class="sub section-gap">Acceptance criteria</h3>
                @if ($feature->acceptanceCriteria->isEmpty())
                    <p class="muted small">No acceptance criteria yet. The agent adds them when it records the feature.</p>
                @else
                    <ul class="criteria">
                        @foreach ($feature->acceptanceCriteria as $criterion)
                            <li>{{ $criterion->description }}
                                @if ($criterion->source !== \App\Enums\ClaimSource::UserEntered)
                                    <span class="badge">{{ $criterion->source->label() }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            @php($shown = $diagrams->firstWhere('id', $shownDiagram) ?? $diagrams->first())
            <div class="card diagrams-card" x-data="{ copied: false, open: false }">
                <div class="card-head">
                    <h2>Diagrams @if ($diagrams->isNotEmpty()) <span class="count">{{ $diagrams->count() }}</span> @endif</h2>
                    {{-- Unseen can't read the code: the coding agent checks the diagrams against it and updates them --}}
                    <div class="diagram-sync">
                        <button class="btn ghost sm" type="button" title="Prompt for your coding agent to check these diagrams against the current code"
                            x-on:click="navigator.clipboard?.writeText($refs.diagramPrompt.value).then(() => { copied = true; setTimeout(() => copied = false, 2500) }, () => { open = true; $nextTick(() => $refs.diagramPrompt.select()) })">
                            <span x-show="! copied">Copy sync prompt</span><span x-show="copied" x-cloak>Copied ✓</span>
                        </button>
                        <livewire:proceed-button :project="$feature->project" kind="diagrams" :ref="$feature->id" :label="$diagramSync['label']" :small="true" :key="'proceed-diagrams-'.$feature->id" />
                    </div>
                </div>
                <div x-show="open" x-cloak>
                    <textarea class="prompt" x-ref="diagramPrompt" readonly aria-label="Diagram sync prompt for your coding agent" x-on:focus="$el.select()">{{ $diagramSync['prompt'] }}</textarea>
                    <p class="hint">Paste it into Claude Code in this project's repository. The diagrams update here when the agent is done.</p>
                </div>

                @if ($diagrams->isEmpty())
                    <div class="empty compact">
                        <p class="muted">No diagrams yet. Coding agents add a <strong>flowchart</strong> of the feature's steps, a <strong>use case</strong> diagram of who uses it, or a <strong>sequence</strong> diagram of how the parts talk to each other.</p>
                    </div>
                @else
                    <div class="diagram-tabs" role="tablist" aria-label="Diagrams">
                        @foreach ($diagrams as $diagram)
                            <button type="button" role="tab" aria-selected="{{ $diagram->is($shown) ? 'true' : 'false' }}" @class(['active' => $diagram->is($shown)])
                                wire:click="$set('shownDiagram', {{ $diagram->id }})" wire:key="dt-{{ $diagram->id }}">
                                <span class="type type-{{ $diagram->type->value }}">{{ $diagram->type->label() }}</span>{{ $diagram->title }}
                            </button>
                        @endforeach
                    </div>
                    {{-- Full screen: the browser's Fullscreen API where it exists, a fixed overlay either way (iPhone Safari has no element full screen) --}}
                    <div class="diagram-view" role="tabpanel" x-data="UnseenDiagrams.viewer()"
                        :class="{ 'is-full': full, 'is-dragging': drag }" :style="full ? `--zoom: ${zoom}` : ''"
                        x-effect="document.documentElement.classList.toggle('diagram-full-open', full)"
                        x-on:fullscreenchange.document="if (! document.fullscreenElement && full) exit()"
                        x-on:keydown.escape.window="full && exit()" x-on:keydown.window="keys($event)"
                        x-on:wheel="wheel($event)" x-on:pointerdown="panStart($event)" x-on:pointermove="panMove($event)"
                        x-on:pointerup="panEnd()" x-on:pointercancel="panEnd()">
                        <div class="diagram-full-head" x-show="full" x-cloak>
                            <strong><span class="muted">{{ $shown->type->label() }} ·</span> {{ $shown->title }}</strong>
                            <div class="zoom-controls" role="group" aria-label="Zoom">
                                <button class="btn sm" type="button" x-on:click="zoomOut()" :disabled="zoom <= 0.5" title="Zoom out (−)" aria-label="Zoom out">−</button>
                                <button class="btn ghost sm zoom-level" type="button" x-on:click="fit()" title="Fit to screen (0)" x-text="Math.round(zoom * 100) + '%'">100%</button>
                                <button class="btn sm" type="button" x-on:click="zoomIn()" :disabled="zoom >= 6" title="Zoom in (+)" aria-label="Zoom in">+</button>
                                <span class="muted small zoom-hint">Ctrl/⌘ + scroll or pinch to zoom · drag to move</span>
                            </div>
                            <button class="btn sm" type="button" x-ref="exitFull" x-on:click="exit()">Exit full screen <span class="muted">Esc</span></button>
                        </div>
                        <div class="diagram" data-diagram data-definition="{{ $shown->definition }}" aria-label="{{ $shown->type->label() }} diagram: {{ $shown->title }}"
                            wire:key="dg-{{ $shown->id }}-{{ $shown->updated_at?->timestamp }}" wire:ignore
                            x-data x-init="$nextTick(() => window.UnseenDiagrams?.render($el))"></div>
                        <div class="diagram-foot small">
                            <span class="muted">{{ $shown->source->label() }} · updated {{ $shown->updated_at->diffForHumans() }}</span>
                            <span class="spacer"></span>
                            <button class="btn ghost sm" type="button" x-on:click="enter()" title="View this diagram full screen">
                                <svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2.5 6V2.5H6M10 2.5h3.5V6M13.5 10v3.5H10M6 13.5H2.5V10"/></svg>
                                Full screen
                            </button>
                            <details class="source">
                                <summary>Source</summary>
                                <pre><code>{{ $shown->definition }}</code></pre>
                            </details>
                        </div>
                    </div>
                @endif
            </div>

        </div>

        <aside class="dash-side">
            <div class="card">
                <h2>Automated checks</h2>
                @if ($checks->isEmpty())
                    <p class="small"><span class="badge warn">No automated checks</span></p>
                    <p class="muted small">No tests are linked yet, so test runs can't verify this feature.</p>
                @else
                    <ul class="plain">
                        @foreach ($checks as $check)
                            @php($result = $check->latestResult)
                            <li class="small" wire:key="chk-{{ $check->id }}">
                                @if (! $result)
                                    <span class="badge">Not run yet</span>
                                @elseif ($result->outcome === 'passed')
                                    <span class="badge ok">Passed</span>
                                @elseif ($result->outcome === 'failed')
                                    <span class="badge bad">Failed</span>
                                @else
                                    <span class="badge warn">No matching tests</span>
                                @endif
                                <code>{{ $check->pattern }}</code>
                                @if ($check->description) <span class="muted">· {{ $check->description }}</span> @endif
                                @if ($result)
                                    <div class="muted">
                                        {{ $result->matched }} {{ Str::plural('test', $result->matched) }} matched ·
                                        <a href="{{ route('test-runs.show', $result->test_run_id) }}" wire:navigate>run #{{ $result->test_run_id }}</a>
                                        @if ($result->testRun?->git_commit) at <code>{{ Str::limit($result->testRun->git_commit, 10, '') }}</code> @endif
                                        · {{ $result->created_at->diffForHumans() }}
                                    </div>
                                    @foreach ($result->failures ?? [] as $failure)
                                        <div class="error"><code>{{ $failure['id'] }}</code>@if (! empty($failure['message'])): {{ $failure['message'] }}@endif</div>
                                    @endforeach
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
                <details class="help small">
                    <summary class="muted">How checks verify a feature</summary>
                    <p class="muted">Agents link tests to the feature. When a trusted test run (CI, or <code>unseen verify</code> with a verify token) passes every check at a clean commit, and the feature is implemented, it is marked verified. Any failing check marks it failed.</p>
                </details>
            </div>

            <div class="card">
                <div class="card-head"><h2>Timeline</h2><span class="muted small">Status changes and agent reports</span></div>
                @if ($timeline->isEmpty())
                    <p class="muted small">Nothing has happened to this feature yet.</p>
                @else
                    <ul class="plain timeline">
                        @foreach ($timeline as $entry)
                            @php($item = $entry['item'])
                            @if ($entry['kind'] === 'change')
                                <li class="small" wire:key="tl-c-{{ $item->id }}">
                                    {{ $item->field === 'status' ? 'Implementation' : 'Verification' }}
                                    {{ $item->from_value ? $changeLabel($item->from_value).' →' : 'set to' }}
                                    <strong>{{ $changeLabel($item->to_value) }}</strong>
                                    @if ($item->note) <div>{{ $item->note }}</div> @endif
                                    <div class="muted">{{ $item->source->label() }}{{ $item->user ? ' ('.$item->user->name.')' : '' }} · {{ $item->created_at->format('j M Y, H:i') }}</div>
                                </li>
                            @else
                                <li class="small report" wire:key="tl-e-{{ $item->id }}">
                                    @if ($item->developmentReport)
                                        Report: <a href="{{ route('reports.show', $item->developmentReport) }}" wire:navigate>{{ Str::limit($item->developmentReport->summary, 100) }}</a>
                                    @else
                                        Evidence recorded
                                    @endif
                                    @if ($item->claimed_status) · claimed <x-status-badge :status="$item->claimed_status" /> @endif
                                    @if ($item->note) <div>{{ $item->note }}</div> @endif
                                    <div class="muted">{{ $item->source->label() }} · {{ $item->created_at->format('j M Y, H:i') }}</div>
                                </li>
                            @endif
                        @endforeach
                    </ul>
                @endif
            </div>

            @if ($tickets->isNotEmpty())
                <div class="card">
                    <h2>Tickets <span class="count">{{ $tickets->count() }}</span></h2>
                    <ul class="plain tickets">
                        @foreach ($tickets as $ticket)
                            <x-ticket-row :ticket="$ticket" class="small" wire:key="ft-{{ $ticket->id }}" />
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($repositoryEvidence->isNotEmpty())
                <div class="card">
                    <h2>Found in the repository</h2>
                    <p class="muted small">Code that seems related. This shows the feature may exist, not that it works.</p>
                    @foreach ($repositoryEvidence as $suggestion)
                        <details wire:key="re-{{ $suggestion->id }}">
                            <summary class="small">{{ $suggestion->name }} · {{ count($suggestion->evidence) }} items
                                @if ($suggestion->lastScan) · scanned {{ $suggestion->lastScan->created_at->diffForHumans() }} @endif</summary>
                            <ul class="plain small">
                                @foreach (array_slice($suggestion->evidence, 0, 25) as $item)
                                    <li><span class="badge">{{ $item['type'] }}</span> <code>{{ $item['path'] }}</code>
                                        @if (! empty($item['detail'])) <span class="muted">{{ $item['detail'] }}</span> @endif</li>
                                @endforeach
                            </ul>
                        </details>
                    @endforeach
                </div>
            @endif
        </aside>
    </div>
</div>
