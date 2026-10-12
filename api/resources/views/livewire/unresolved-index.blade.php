<div>
    <div class="page-head">
        <div>
            <a href="{{ route('projects.show', $project) }}" wire:navigate class="back">{{ $project->name }}</a>
            <h1>Unresolved work</h1>
            <p class="muted">Things reports said were left open. Agents close them in a later report, recorded as the reporter's claim.</p>
        </div>
    </div>

    <x-project-tabs :project="$project" />

    <div class="filters">
        <button type="button" wire:click="$set('status', 'open')" @class(['btn', 'primary' => $current === 'open'])>Open ({{ $counts['open'] ?? 0 }})</button>
        <button type="button" wire:click="$set('status', 'resolved')" @class(['btn', 'primary' => $current === 'resolved'])>Done ({{ $counts['resolved'] ?? 0 }})</button>
    </div>

    @if ($current === 'open' && ($counts['open'] ?? 0) > 0)
        <div class="waiting-strip" role="group" aria-label="Filter by who has to act">
            @foreach ([
                ['team', \App\Enums\WaitingOn::Team->heading(), 'bad', 'Decide, then tell your agent'],
                ['client', \App\Enums\WaitingOn::Client->heading(), 'warn', 'Ask the client'],
                ['unsorted', 'Not sorted yet', 'none', 'Ask your agent to sort them'],
                ['agent', 'Agent can do', 'info', 'No decision needed'],
            ] as [$key, $title, $tone, $hint])
                <button type="button" wire:click="$set('waiting', '{{ $waitingFilter === $key ? '' : $key }}')"
                    @class(['waiting-tile', $tone, 'active' => $waitingFilter === $key, 'empty' => $waitingCounts[$key] === 0])
                    aria-pressed="{{ $waitingFilter === $key ? 'true' : 'false' }}">
                    <span class="num">{{ $waitingCounts[$key] }}</span>
                    <span class="lbl">{{ $title }}</span>
                    <span class="hint">{{ $hint }}</span>
                </button>
            @endforeach
        </div>
        @if ($waitingFilter) <p class="small"><button class="btn link" type="button" wire:click="$set('waiting', '')">Show everyone's items</button></p> @endif
    @endif

    @if ($sortPrompt)
        <div class="card next-step sort-step" x-data="{ copied: false, open: false }">
            <div class="next-row">
                <span class="connect-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M6 12h12M10 18h4"/></svg>
                </span>
                <div class="next-text">
                    <span class="muted small">{{ $waitingCounts['unsorted'] }} open {{ Str::plural('item', $waitingCounts['unsorted']) }} not sorted yet</span>
                    <strong>Ask your agent which need a decision from you or the client</strong>
                </div>
                <button class="btn ghost" type="button" x-on:click="open = ! open" x-text="open ? 'Hide prompt' : 'Preview'">Preview</button>
                <button class="btn primary" type="button"
                    x-on:click="navigator.clipboard?.writeText($refs.sortPrompt.value).then(() => { copied = true; setTimeout(() => copied = false, 2500) }, () => { open = true; $nextTick(() => $refs.sortPrompt.select()) })">
                    <span x-show="! copied">Copy prompt</span><span x-show="copied" x-cloak>Copied ✓</span>
                </button>
                <livewire:proceed-button :project="$project" kind="sort" :key="'proceed-sort'" />
            </div>
            <div x-show="open" x-cloak>
                <textarea class="prompt" x-ref="sortPrompt" readonly aria-label="Prompt for your coding agent" x-on:focus="$el.select()">{{ $sortPrompt }}</textarea>
                <p class="hint">The agent checks each item and records whether it waits on you, the client, or nobody, splitting mixed items. It fixes nothing in this step.</p>
            </div>
        </div>
    @endif

    @if ($allPrompt && ($counts['open'] ?? 0) - $waitingCounts['team'] - $waitingCounts['client'] > 0)
        <div class="card next-step" x-data="{ copied: false, open: false }">
            <div class="next-row">
                <span class="connect-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 17l6-6-6-6M12 19h8"/></svg>
                </span>
                <div class="next-text">
                    <span class="muted small">Next step for your coding agent</span>
                    @php($doable = ($counts['open'] ?? 0) - $waitingCounts['team'] - $waitingCounts['client'])
                    <strong>{{ $doable == 1 ? 'Resolve the open item that needs no decision' : 'Work through the '.$doable.' open items that need no decision' }}</strong>
                </div>
                <button class="btn ghost" type="button" x-on:click="open = ! open" x-text="open ? 'Hide prompt' : 'Preview'">Preview</button>
                <button class="btn primary" type="button"
                    x-on:click="navigator.clipboard?.writeText($refs.allPrompt.value).then(() => { copied = true; setTimeout(() => copied = false, 2500) }, () => { open = true; $nextTick(() => $refs.allPrompt.select()) })">
                    <span x-show="! copied">Copy prompt</span><span x-show="copied" x-cloak>Copied ✓</span>
                </button>
                <livewire:proceed-button :project="$project" kind="items" :key="'proceed-items'" />
            </div>
            <div x-show="open" x-cloak>
                <textarea class="prompt" x-ref="allPrompt" readonly aria-label="Prompt for your coding agent" x-on:focus="$el.select()">{{ $allPrompt }}</textarea>
                <p class="hint">Paste it into Claude Code in this project's repository. It contains no token. Items that need a decision or access are handed back to you, not guessed.</p>
            </div>
        </div>
    @endif

    <div class="card">
        @if ($items->isEmpty())
            <p class="muted">{{ $current === 'open' ? 'Nothing open.' : 'Nothing closed yet.' }}</p>
        @else
            <ul class="plain">
                @foreach ($items as $item)
                    @php($group = $item->waiting_on?->value ?? 'unsorted')
                    @if ($current === 'open' && ($loop->first || $group !== ($items[$loop->index - 1]->waiting_on?->value ?? 'unsorted')))
                        <li class="group-head {{ $item->waiting_on?->tone() ?? 'none' }}" wire:key="group-{{ $group }}">
                            <strong>{{ $item->waiting_on?->heading() ?? 'Not sorted yet' }}</strong>
                            <span class="muted small">{{ $item->waiting_on?->explanation() ?? 'Nobody has said yet whether these need a decision. Use "Copy prompt" above to have your agent sort them.' }}</span>
                        </li>
                    @endif
                    <li wire:key="item-{{ $item->id }}" @class(['unresolved-item', 'waiting-'.$group => $current === 'open'])
                        x-data="{ deciding: false }" x-on:decided.window="if ($event.detail.id === {{ $item->id }}) deciding = false">
                        @if ($current === 'open' && $item->waiting_on)
                            <span class="badge decision {{ $item->waiting_on->tone() }}">{{ $item->waiting_on->label() }}</span>
                        @endif
                        <div class="item-row">
                            <div class="item-text">
                                @if ($item->decision_note && $current === 'open')
                                    <div class="needed"><strong>{{ $item->needsDecision() ? 'Needed:' : 'To do:' }}</strong> {{ $item->decision_note }}</div>
                                @endif
                                <span @class(['muted' => $item->decision_note && $current === 'open'])>{{ $item->text }}</span> <span class="muted small">#{{ $item->id }}</span>
                                @if ($item->decision)
                                    <div class="decided"><strong>Decided:</strong> {{ $item->decision }}
                                        <span class="muted small">· {{ $item->decidedBy?->name ?? 'Someone' }}, {{ $item->decided_at?->format('j M Y') }}</span>
                                        @if ($current === 'open') @can('update', $project) <button class="btn link small" type="button" x-on:click="deciding = ! deciding; $wire.decisions[{{ $item->id }}] ??= @js($item->decision)">Change</button> @endcan @endif
                                    </div>
                                @endif
                            </div>
                            @if (isset($clientQuestions[$item->id]))
                                <button class="btn sm" type="button" x-data="{ copied: false }" data-prompt="{{ $clientQuestions[$item->id] }}"
                                    title="Copy a short message asking the client for this decision"
                                    x-on:click="navigator.clipboard?.writeText($el.dataset.prompt).then(() => { copied = true; setTimeout(() => copied = false, 2500) })">
                                    <span x-show="! copied">Copy message for client</span><span x-show="copied" x-cloak>Copied ✓</span>
                                </button>
                            @endif
                            @if (isset($prompts[$item->id]) && $item->needsDecision())
                                @can('update', $project)
                                    <button class="btn sm primary" type="button" x-on:click="deciding = ! deciding; $nextTick(() => deciding && $refs.decision.focus())"
                                        title="Write the decision here. The agent's prompt is then rebuilt with it">{{ $item->waiting_on === \App\Enums\WaitingOn::Client ? 'Record client\'s answer' : 'Decide' }}</button>
                                @endcan
                            @elseif (isset($prompts[$item->id]))
                                <button class="btn sm copy-item" type="button" x-data="{ copied: false }" data-prompt="{{ $prompts[$item->id] }}"
                                    title="Copy a prompt that asks your coding agent to resolve this item"
                                    x-on:click="navigator.clipboard?.writeText($el.dataset.prompt).then(() => { copied = true; setTimeout(() => copied = false, 2500) })">
                                    <span x-show="! copied">Copy prompt</span><span x-show="copied" x-cloak>Copied ✓</span>
                                </button>
                                <livewire:proceed-button :project="$project" kind="item" :ref="$item->id" :small="true" :key="'proceed-item-'.$item->id" />
                            @endif
                        </div>
                        @if ($current === 'open')
                            @can('update', $project)
                                <form class="decide-form" wire:submit="decide({{ $item->id }})" x-show="deciding" x-cloak>
                                    <label for="decision-{{ $item->id }}">{{ $item->waiting_on === \App\Enums\WaitingOn::Client ? 'What did the client decide?' : 'Your decision' }}</label>
                                    <textarea id="decision-{{ $item->id }}" x-ref="decision" wire:model="decisions.{{ $item->id }}" rows="3" maxlength="2000"
                                        placeholder="e.g. Keep the extras: add them to the feature's description and criteria."></textarea>
                                    @error('decisions.'.$item->id) <div class="error">{{ $message }}</div> @enderror
                                    <p class="hint">Saved with your name. The item then moves to what your agent can do, and its prompt includes this decision.</p>
                                    <div class="form-actions">
                                        <button class="btn primary sm" type="submit" wire:loading.attr="disabled">Save decision</button>
                                        <button class="btn ghost sm" type="button" x-on:click="deciding = false">Cancel</button>
                                    </div>
                                </form>
                            @endcan
                        @endif
                        <div class="muted small">
                            @if ($item->report) From <a href="{{ route('reports.show', $item->report) }}" wire:navigate>report #{{ $item->report->id }}</a> · @endif
                            {{ $item->created_at->diffForHumans() }}
                        </div>
                        @if ($current === 'resolved')
                            <div class="small">
                                <span class="badge ok">Done</span>
                                {{ $item->resolved_source?->label() }}{{ $item->resolvedBy ? ' ('.$item->resolvedBy->name.')' : '' }}
                                · {{ $item->resolved_at?->diffForHumans() }}
                                @if ($item->resolved_report_id) · <a href="{{ route('reports.show', $item->resolved_report_id) }}" wire:navigate>report #{{ $item->resolved_report_id }}</a> @endif
                                @if ($item->resolution_note) <div>{{ $item->resolution_note }}</div> @endif
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
            {{ $items->links('partials.pagination') }}
        @endif
    </div>
</div>
