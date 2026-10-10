@props(['progress', 'total', 'implemented', 'verified'])
@if ($total === 0)
    <p class="muted small">No features tracked yet, so progress is unknown.</p>
@else
    <div class="progress">
        <div class="row"><span>Implemented</span><span>{{ $progress['implemented_percent'] }}%</span></div>
        <div class="bar"><div class="fill" style="width: {{ $progress['implemented_percent'] }}%"></div></div>
    </div>
    <div class="progress">
        <div class="row"><span>Implemented and verified</span><span>{{ $progress['verified_percent'] }}%</span></div>
        <div class="bar"><div class="fill ok" style="width: {{ $progress['verified_percent'] }}%"></div></div>
    </div>
    <p class="muted small" title="Progress = features with the status divided by all tracked features, rounded down.">
        {{ $implemented }} of {{ $total }} tracked {{ Str::plural('feature', $total) }} implemented; {{ $verified }} of those verified.
    </p>
@endif
