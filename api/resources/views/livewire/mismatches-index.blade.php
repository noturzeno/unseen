<div>
    <div class="page-head">
        <div>
            <a href="{{ route('projects.show', $project) }}" wire:navigate class="back">{{ $project->name }}</a>
            <h1>PRD vs design</h1>
            <p class="muted">Where the designer's design differs from the PRD. The build follows the design, so each difference needs a decision: follow the PRD, or keep the design (and update the PRD). Your coding agent records the differences and carries out what you decide; it never decides.</p>
        </div>
    </div>

    <x-project-tabs :project="$project" />

    @php($total = array_sum([$counts['open'], $counts['decided'], $counts['applied']]))
    @if ($total > 0)
        <div class="waiting-strip" role="group" aria-label="Filter mismatches">
            @foreach ([
                ['team', 'Your decision needed', 'bad', $counts['team'], 'Decide below'],
                ['client', 'Client decision needed', 'warn', $counts['client'], 'Ask the client, then record it'],
                ['decided', 'Decided, to apply', 'info', $counts['decided'], 'Your agent carries these out'],
                ['applied', 'Applied', 'none', $counts['applied'], 'Code or PRD now match'],
            ] as [$key, $title, $tone, $n, $hint])
                <button type="button" wire:click="$set('show', '{{ $current === $key ? '' : $key }}')"
                    @class(['waiting-tile', $tone, 'active' => $current === $key, 'empty' => $n === 0]) aria-pressed="{{ $current === $key ? 'true' : 'false' }}">
                    <span class="num">{{ $n }}</span><span class="lbl">{{ $title }}</span><span class="hint">{{ $hint }}</span>
                </button>
            @endforeach
        </div>
    @endif

    @foreach (array_filter([
        $applyPrompt ? ['apply', $applyPrompt, 'Apply '.$counts['decided'].' '.Str::plural('decision', $counts['decided']), 'Your agent changes the code or the PRD to match what you decided.', 'apply_mismatches'] : null,
        ['find', $findPrompt, $total ? 'Check again for PRD vs design mismatches' : 'Find PRD vs design mismatches', 'Your agent compares the PRD, the design and the code, and records each difference here. It changes nothing.', 'find_mismatches'],
    ]) as [$id, $prompt, $title, $hint, $kind])
        <div class="card next-step" x-data="{ copied: false, open: false }" wire:key="mp-{{ $id }}">
            <div class="next-row">
                <span class="connect-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 17l6-6-6-6M12 19h8"/></svg>
                </span>
                <div class="next-text"><span class="muted small">Next step for your coding agent</span><strong>{{ $title }}</strong></div>
                <button class="btn ghost" type="button" x-on:click="open = ! open" x-text="open ? 'Hide prompt' : 'Preview'">Preview</button>
                <button @class(['btn', 'primary' => $id === 'apply' || ! $total]) type="button"
                    x-on:click="navigator.clipboard?.writeText($refs.p.value).then(() => { copied = true; setTimeout(() => copied = false, 2500) }, () => { open = true; $nextTick(() => $refs.p.select()) })">
                    <span x-show="! copied">Copy prompt</span><span x-show="copied" x-cloak>Copied ✓</span>
                </button>
                <livewire:proceed-button :project="$project" :kind="$kind" :key="'proceed-'.$kind" />
            </div>
            <div x-show="open" x-cloak>
                <textarea class="prompt" x-ref="p" readonly aria-label="Prompt for your coding agent" x-on:focus="$el.select()">{{ $prompt }}</textarea>
                <p class="hint">{{ $hint }} The prompt contains no token.</p>
            </div>
        </div>
    @endforeach

    <div class="card">
        @if ($items->isEmpty())
            <p class="muted">{{ $total ? 'Nothing here.' : 'No mismatches recorded yet. Use the prompt above to have your coding agent find them.' }}</p>
        @else
            <div class="mismatch-list">
                @foreach ($items as $m)
                    <x-mismatch-card :mismatch="$m" :decidable="true" wire:key="m-{{ $m->id }}" />
                @endforeach
            </div>
            {{ $items->links('partials.pagination') }}
        @endif
    </div>
</div>
