@props(['mismatch', 'decidable' => false])
@php($m = $mismatch)
{{-- One PRD vs design mismatch: the two sides, what was built, and the decision (or the buttons to make it). --}}
<article {{ $attributes->class(['mismatch', 'status-'.$m->status->value, 'by-'.$m->decide_by->value => $m->status === \App\Enums\MismatchStatus::Open]) }}>
    <header class="mismatch-head">
        @if ($m->status === \App\Enums\MismatchStatus::Open)
            <span class="badge decision {{ $m->decide_by->tone() }}">{{ $m->decide_by->label() }}</span>
        @else
            <span class="badge {{ $m->status->tone() }}">{{ $m->status->label() }}</span>
        @endif
        <h3>{{ $m->title }}</h3>
        <span class="muted small">
            #{{ $m->id }}@if ($m->reference) · {{ $m->reference }}@endif
            @if ($m->feature) · <a href="{{ route('features.show', $m->feature_id) }}" wire:navigate>{{ $m->feature->name }}</a>@endif
        </span>
    </header>

    <div class="sides">
        <div @class(['side', 'prd', 'chosen' => $m->decision === \App\Enums\MismatchDecision::FollowPrd, 'built' => $m->built_to === \App\Enums\BuiltTo::Prd])>
            <span class="side-label">PRD says @if ($m->built_to === \App\Enums\BuiltTo::Prd) <span class="badge ok">Built</span> @endif</span>
            <p class="prewrap">{{ $m->prd_says }}</p>
        </div>
        <div @class(['side', 'design', 'chosen' => $m->decision === \App\Enums\MismatchDecision::FollowDesign, 'built' => $m->built_to === \App\Enums\BuiltTo::Design])>
            <span class="side-label">Design says @if ($m->built_to === \App\Enums\BuiltTo::Design) <span class="badge ok">Built</span> @endif</span>
            <p class="prewrap">{{ $m->design_says }}</p>
        </div>
    </div>

    <p class="small built-line"><strong>{{ $m->built_to->label() }}</strong>@if ($m->built_note) · {{ $m->built_note }}@endif</p>
    @if ($m->impact) <p class="small impact"><strong>If we change it:</strong> {{ $m->impact }}</p> @endif

    @if ($m->status === \App\Enums\MismatchStatus::Open && $decidable)
        <div class="decide">
            <input type="text" wire:model="notes.{{ $m->id }}" maxlength="5000" placeholder="Note for the agent (required for “Something else”)" aria-label="Decision note for mismatch #{{ $m->id }}">
            <div class="decide-buttons">
                <button type="button" class="btn" wire:click="decide({{ $m->id }}, 'follow_prd')">Follow the PRD</button>
                <button type="button" class="btn" wire:click="decide({{ $m->id }}, 'follow_design')">Keep the design</button>
                <button type="button" class="btn ghost" wire:click="decide({{ $m->id }}, 'other')">Something else</button>
            </div>
            @error('decision_note') <p class="error small">{{ $message }}</p> @enderror
            @if ($m->decide_by === \App\Enums\WaitingOn::Client)
                <p class="muted small">The client decides this one. Record their answer here once you have it.</p>
            @endif
        </div>
    @elseif ($m->decision)
        <div class="decided small">
            <strong>Decision: {{ $m->decision->label() }}</strong>
            @if ($m->decision_note) · {{ $m->decision_note }} @endif
            <div class="muted">{{ $m->decidedBy?->name ?? 'Someone' }} · {{ $m->decided_at?->format('j M Y, H:i') }}
                @if ($m->status === \App\Enums\MismatchStatus::Decided)
                    · next: {{ $m->decision->action() }}
                    @if ($decidable) · <button type="button" class="btn link" wire:click="reopen({{ $m->id }})">Change decision</button> @endif
                @endif
            </div>
            @if ($m->applied_at)
                <div class="muted">Applied by the agent {{ $m->applied_at->format('j M Y') }}@if ($m->applied_note): {{ $m->applied_note }}@endif</div>
            @endif
        </div>
    @endif
</article>
