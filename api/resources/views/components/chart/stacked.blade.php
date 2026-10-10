{{--
    Part-to-whole bar. Segments: list of [label, count, tone, click?]; tone is one of ok, info, warn, bad, none,
    none-2. click, when given, is a Livewire action (e.g. "filterFeatures('3', 'implemented')") run on click/Enter.
    Identity never relies on colour alone: the legend names every segment with its count, and each
    segment shows its label and value on hover or keyboard focus.
--}}
@props(['title', 'segments', 'legend' => true])
@php
    $total = array_sum(array_column($segments, 1));
@endphp
<div {{ $attributes->class('chart-stacked') }}>
    @if ($title) <div class="chart-label">{{ $title }}</div> @endif
    @if ($total === 0)
        <div class="stack empty" aria-label="{{ $title }}: no features"></div>
    @else
        <div class="stack" role="img" aria-label="{{ $title }}: {{ collect($segments)->filter(fn ($s) => $s[1] > 0)->map(fn ($s) => $s[0].' '.$s[1])->implode(', ') }}">
            @foreach ($segments as $segment)
                @php([$label, $count, $tone] = $segment)
                @php($click = $segment[3] ?? null)
                @continue($count === 0)
                <span @class(['seg', $tone, 'clickable' => $click]) style="flex-grow: {{ $count }}" tabindex="0"
                    @if ($click) role="button" aria-label="Show {{ $count }} {{ $label }}" wire:click="{{ $click }}" wire:keydown.enter="{{ $click }}" @endif>
                    <span class="tip" role="tooltip"><strong>{{ $count }}</strong> {{ $label }} · {{ intdiv($count * 100, $total) }}%</span>
                </span>
            @endforeach
        </div>
    @endif
    @if ($legend)
        <ul class="chart-legend">
            @foreach ($segments as $segment)
                @php([$label, $count, $tone] = $segment)
                @php($click = $segment[3] ?? null)
                <li @class(['zero' => $count === 0])>
                    @if ($click && $count > 0)
                        <button type="button" class="legend-link" wire:click="{{ $click }}"><span class="key {{ $tone }}"></span>{{ $label }} <strong>{{ $count }}</strong></button>
                    @else
                        <span class="key {{ $tone }}"></span>{{ $label }} <strong>{{ $count }}</strong>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
