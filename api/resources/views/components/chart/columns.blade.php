{{--
    Single-series column chart. Points: list of [label, value, tooltip label]. One hue, so no legend:
    the card title names the series. The highest column is labelled; every value is on hover/focus
    and in the table view.
--}}
@props(['points', 'unit' => '', 'xLabel' => 'Week'])
@php
    $max = max(1, ...array_column($points, 1));
    $step = $max <= 4 ? 1 : (int) ceil($max / 4);
    $top = $step * (int) ceil($max / $step);
    $peak = array_search(max(array_column($points, 1)), array_column($points, 1), true);
    $every = count($points) > 8 ? 3 : 1;
@endphp
<div {{ $attributes->class('chart-columns') }}>
    <div class="plot" role="img" aria-label="{{ collect($points)->map(fn ($p) => $p[2].': '.$p[1])->implode(', ') }}">
        <div class="grid-lines" aria-hidden="true">
            @foreach ([$top, intdiv($top, 2), 0] as $tick)
                <span style="bottom: {{ $tick / $top * 100 }}%"><em>{{ number_format($tick) }}</em></span>
            @endforeach
        </div>
        <div class="cols">
            @foreach ($points as $i => [$label, $value, $tipLabel])
                <span class="col" tabindex="0">
                    <span class="bar" style="height: {{ $value / $top * 100 }}%">
                        @if ($i === $peak && $value > 0) <span class="cap">{{ $value }}</span> @endif
                    </span>
                    <span class="tip" role="tooltip"><strong>{{ $value }}</strong> {{ $unit }} · {{ $tipLabel }}</span>
                </span>
            @endforeach
        </div>
    </div>
    <div class="x-labels" aria-hidden="true">
        @foreach ($points as $i => [$label])
            <span>{{ $i % $every === 0 || $i === count($points) - 1 ? $label : '' }}</span>
        @endforeach
    </div>
    <details class="chart-table">
        <summary class="small muted">Show as table</summary>
        <table>
            <thead><tr><th>{{ $xLabel }}</th><th>{{ ucfirst($unit) }}</th></tr></thead>
            <tbody>
                @foreach ($points as [$label, $value, $tipLabel])
                    <tr><td>{{ $tipLabel }}</td><td>{{ $value }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </details>
</div>
