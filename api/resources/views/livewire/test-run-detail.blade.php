@php
    $labels = [
        'verified' => ['Verified', 'ok'],
        'failed' => ['Failed', 'bad'],
        'incomplete' => ['Incomplete: some checks did not run', 'warn'],
        'passed_not_applied' => ['Passed, not applied', 'info'],
        'not_covered' => ['Not covered by this run', ''],
    ];
@endphp
<div>
    <div class="page-head">
        <div>
            <a href="{{ route('projects.show', $testRun->project) }}" wire:navigate class="back">{{ $testRun->project->name }}</a>
            <h1>Test run #{{ $testRun->id }}</h1>
            <p class="muted small">
                {{ $testRun->created_at->format('Y-m-d H:i') }}
                @if ($testRun->runner) · {{ $testRun->runner }} @endif
                @if ($testRun->user) · submitted by {{ $testRun->user->name }} @endif
                @if ($testRun->git_branch) · <code>{{ $testRun->git_branch }}</code> @endif
                @if ($testRun->git_commit) at <code>{{ Str::limit($testRun->git_commit, 12, '') }}</code> @else · <span class="badge warn">no commit</span> @endif
                @if ($testRun->git_dirty) <span class="badge warn">uncommitted changes</span> @endif
            </p>
        </div>
    </div>

    <div class="stats">
        <div class="stat"><div class="num">{{ $testRun->total }}</div><div class="lbl">Tests</div></div>
        <div class="stat ok"><div class="num">{{ $testRun->passed }}</div><div class="lbl">Passed</div></div>
        <div @class(['stat bad', 'hot' => $testRun->failed > 0])><div class="num">{{ $testRun->failed }}</div><div class="lbl">Failed or errored</div></div>
        <div class="stat"><div class="num">{{ $testRun->skipped }}</div><div class="lbl">Skipped</div></div>
    </div>

    <div class="card">
        <h2>Effect on features</h2>
        @if ($outcomes->isEmpty())
            <p class="muted small">No feature in this project has automated checks yet, so this run changed nothing.</p>
        @else
            <ul class="plain">
                @foreach ($outcomes as $outcome)
                    @php([$label, $tone] = $labels[$outcome['outcome']] ?? [$outcome['outcome'], ''])
                    @php($feature = $features[$outcome['feature_id']] ?? null)
                    <li wire:key="o-{{ $outcome['feature_id'] }}">
                        @if ($feature) <a href="{{ route('features.show', $feature) }}" wire:navigate>{{ $feature->name }}</a> @else <span class="muted">Deleted feature</span> @endif
                        <span @class(['badge', $tone])>{{ $label }}</span>
                        @if ($outcome['reason']) <div class="muted small">{{ $outcome['reason'] }}</div> @endif
                        @foreach ($checkResults[$outcome['feature_id']] ?? [] as $result)
                            <div class="small">
                                <code>{{ $result->check?->pattern }}</code>: {{ $result->outcome === 'missing' ? 'no matching tests ran' : $result->outcome }} ({{ $result->matched }} matched)
                                @foreach ($result->failures ?? [] as $failure)
                                    <div class="error"><code>{{ $failure['id'] }}</code>@if (! empty($failure['message'])): {{ $failure['message'] }}@endif</div>
                                @endforeach
                            </div>
                        @endforeach
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
