<div>
    <div class="page-head">
        <div>
            <a href="{{ route('projects.index') }}" wire:navigate class="back">Projects</a>
            <h1>
                {{ $project->name }}
                @if ($project->isArchived()) <span class="badge">Archived</span> @endif
            </h1>
            @if ($project->description) <p class="muted">{{ $project->description }}</p> @endif
            @if ($project->tech_stack || $project->repository_url)
                <div class="meta small muted">
                    @if ($project->tech_stack) <span>{{ $project->tech_stack }}</span> @endif
                    @if ($project->tech_stack && $project->repository_url) <span>·</span> @endif
                    @if ($project->repository_url) <code>{{ $project->repository_url }}</code> @endif
                </div>
            @endif
        </div>
    </div>

    <x-project-tabs :project="$project" />

    @if ($summary['total'] === 0 && $summary['reports'] === 0)
        <livewire:connect-agent :project="$project" :intro="true" />
    @endif

    @if ($nextSteps->isNotEmpty())
        <div class="card next-steps" x-data="{ all: false }">
            <div class="card-head">
                <h2>Suggested next steps</h2>
                <span class="muted small">Ranked: broken first, then blocked, then new work. Copy one into your coding agent.</span>
            </div>
            <ol class="steps-list">
                @foreach ($nextSteps as $i => $step)
                    <li wire:key="ns-{{ $i }}" @if ($i >= 5) x-show="all" x-cloak @endif>
                        <span @class(['prio', 'high' => $step['priority'] === 1, 'medium' => $step['priority'] === 2, 'low' => $step['priority'] === 3])>
                            {{ [1 => 'High', 2 => 'Medium', 3 => 'Low'][$step['priority']] }}
                        </span>
                        <div class="step-body">
                            <a href="{{ $step['url'] }}" wire:navigate class="step-title">{{ $step['title'] }}</a>
                            <span class="muted small">{{ $step['reason'] }}</span>
                        </div>
                        <button class="btn sm" type="button" x-data="{ copied: false }" data-prompt="{{ $step['prompt'] }}"
                            title="Copy a prompt for your coding agent"
                            x-on:click="navigator.clipboard?.writeText($el.dataset.prompt).then(() => { copied = true; setTimeout(() => copied = false, 2500) })">
                            <span x-show="! copied">Copy prompt</span><span x-show="copied" x-cloak>Copied ✓</span>
                        </button>
                        <livewire:proceed-button :project="$project" :kind="$step['run']" :ref="$step['ref']" :small="true" :key="'proceed-step-'.$step['run'].'-'.($step['ref'] ?? 0)" />
                    </li>
                @endforeach
            </ol>
            @if ($nextSteps->count() > 5)
                <button class="btn link small show-more" type="button" x-on:click="all = ! all"
                    x-text="all ? 'Show fewer' : 'Show {{ $nextSteps->count() - 5 }} more'">Show {{ $nextSteps->count() - 5 }} more</button>
            @endif
        </div>
    @endif

    @php($mc = $summary['mismatches'])
    @if ($mc['open'] + $mc['decided'] > 0)
        <div class="card mismatch-summary">
            <div class="card-head">
                <h2>PRD vs design <span class="count">{{ $mc['open'] }}</span></h2>
                <a href="{{ route('mismatches.index', $project) }}" wire:navigate>Decide all</a>
            </div>
            <p class="small">
                @if ($mc['team']) <a class="badge bad" href="{{ route('mismatches.index', [$project, 'show' => 'team']) }}" wire:navigate>{{ $mc['team'] }} need your decision</a> @endif
                @if ($mc['client']) <a class="badge warn" href="{{ route('mismatches.index', [$project, 'show' => 'client']) }}" wire:navigate>{{ $mc['client'] }} need the client</a> @endif
                @if ($mc['decided']) <a class="badge info" href="{{ route('mismatches.index', [$project, 'show' => 'decided']) }}" wire:navigate>{{ $mc['decided'] }} decided, waiting for the agent</a> @endif
                @if ($mc['applied']) <span class="badge ok">{{ $mc['applied'] }} applied</span> @endif
            </p>
            @if ($mismatches->isNotEmpty())
                <div class="mismatch-list">
                    @foreach ($mismatches as $m)
                        <x-mismatch-card :mismatch="$m" wire:key="om-{{ $m->id }}" />
                    @endforeach
                </div>
                @if ($mc['open'] > $mismatches->count())
                    <p class="small"><a href="{{ route('mismatches.index', $project) }}" wire:navigate>Decide all {{ $mc['open'] }} →</a></p>
                @endif
            @endif
        </div>
    @endif

    @php($bs = $summary['by_status'])
    @php($vs = $summary['by_verification_status'])
    <div class="card features-card" id="features" x-data x-on:features-filtered.window="$nextTick(() => $el.scrollIntoView({ block: 'start' }))">
        <div class="card-head">
            <h2>Features <span class="count">{{ $summary['total'] }}</span></h2>
            @if ($filtered) <button class="btn link" type="button" wire:click="clearFilters">Clear filters</button> @endif
        </div>

        <div class="segmented" role="group" aria-label="Filter by implementation status">
            <button type="button" wire:click="$set('status', '')" @class(['active' => $status === '']) aria-pressed="{{ $status === '' ? 'true' : 'false' }}">All <span>{{ $summary['total'] }}</span></button>
            @foreach (\App\Enums\FeatureStatus::cases() as $case)
                @continue($bs[$case->value] === 0 && $status !== $case->value)
                <button type="button" wire:click="$set('status', '{{ $case->value }}')" @class(['active' => $status === $case->value]) aria-pressed="{{ $status === $case->value ? 'true' : 'false' }}">{{ $case->label() }} <span>{{ $bs[$case->value] }}</span></button>
            @endforeach
        </div>

        @if ($showing)
            <p class="showing small">Showing: <strong>{{ $showing }}</strong> · {{ $features->total() }} {{ Str::plural('feature', $features->total()) }}
                <button class="btn link" type="button" wire:click="clearFilters">Clear filters</button></p>
        @endif

        <div class="filters">
            <input type="search" class="search" wire:model.live.debounce.300ms="search" placeholder="Search features…" aria-label="Search features by name">
            <select wire:model.live="verification" aria-label="Filter by verification">
                <option value="">All verification</option>
                @foreach (\App\Enums\VerificationStatus::cases() as $case)
                    <option value="{{ $case->value }}">{{ $case->label() }}</option>
                @endforeach
                <option value="unverified">Not verified (any)</option>
            </select>
            <select wire:model.live="category" aria-label="Filter by category">
                <option value="">All categories</option>
                <option value="none">Uncategorised</option>
                @foreach ($categories as $cat)
                    <option value="{{ $cat->id }}">{{ $cat->name }} ({{ $cat->features_count }})</option>
                @endforeach
            </select>
        </div>

        @if ($features->isEmpty())
            <div class="empty">
                @if ($filtered)
                    <p class="muted">No features match these filters.</p>
                    <button class="btn" type="button" wire:click="clearFilters">Clear filters</button>
                @else
                    <p class="muted">No features yet. They appear here once a coding agent records them through the API.</p>
                @endif
            </div>
        @else
            <div class="table-wrap" wire:loading.class="loading" wire:target="status, verification, category, search, clearFilters">
                <table class="features-table">
                    <thead><tr><th>Feature</th><th>Category</th><th>Implementation</th><th>Verification</th><th>Checks</th><th>Updated</th></tr></thead>
                    <tbody>
                        @foreach ($features as $feature)
                            <tr wire:key="f-{{ $feature->id }}">
                                <td>
                                    <a class="row-link" href="{{ route('features.show', $feature) }}" wire:navigate>{{ $feature->name }}</a>
                                    @if ($feature->description) <div class="desc">{{ Str::limit($feature->description, 90) }}</div> @endif
                                </td>
                                <td class="muted">{{ $feature->category?->name ?? '—' }}</td>
                                <td><x-status-badge :status="$feature->status" /></td>
                                <td><x-status-badge :status="$feature->verification_status" /></td>
                                <td class="muted small">{{ $feature->checks_count ?: '—' }}</td>
                                <td class="muted small">{{ $feature->updated_at->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $features->links('partials.pagination') }}
        @endif
    </div>

    <div class="stats">
        <div class="stat"><div class="lbl">Tracked features</div><div class="num">{{ $summary['total'] }}</div></div>
        <div class="stat ok"><div class="lbl">Implemented</div><div class="num">{{ $summary['by_status']['implemented'] }}</div></div>
        <div class="stat info"><div class="lbl">In progress</div><div class="num">{{ $summary['by_status']['in_progress'] }}</div></div>
        <div @class(['stat warn', 'hot' => $summary['by_status']['blocked'] > 0])><div class="lbl">Blocked</div><div class="num">{{ $summary['by_status']['blocked'] }}</div></div>
        <div class="stat info"><div class="lbl">Need verification</div><div class="num">{{ $vs['needs_verification'] }}</div></div>
        <div @class(['stat bad', 'hot' => $vs['failed'] > 0])><div class="lbl">Failed checks</div><div class="num">{{ $vs['failed'] }}</div></div>
    </div>

    <div class="grid charts-row">
        <div class="card">
            <h2>Feature status</h2>
            @if ($summary['total'] === 0)
                <p class="muted small">No features tracked yet.</p>
            @else
                <x-chart.stacked title="Implementation" :segments="$charts['status']" />
                <x-chart.stacked title="Verification" :segments="$charts['verification']" />
            @endif
        </div>

        <div class="card">
            <h2>Activity</h2>
            <p class="muted small">Feature status changes per week, last 12 weeks.</p>
            <x-chart.columns unit="status changes" :points="collect($activity)->map(fn ($w) => [
                $w['week']->format('j M'), $w['count'], 'Week of '.$w['week']->format('j M Y'),
            ])->all()" />
        </div>
    </div>

    <div class="dash">
        <div class="dash-main">
            <x-roadmap-summary :project="$project" :phases="$roadmap" />

            <div class="card">
                <h2>Progress</h2>
                <div class="split">
                    <div>
                        <x-progress-bars :progress="$summary['progress']" :total="$summary['total']"
                            :implemented="$summary['by_status']['implemented']" :verified="$summary['implemented_and_verified']" />
                        <p class="muted small">"Implemented" means someone marked it built. "Verified" means it has been checked to work. A feature can be implemented without being verified.</p>
                    </div>
                    @if ($categories->isNotEmpty() && $byCategory->isNotEmpty())
                        <div>
                            <h3 class="sub">By category</h3>
                            <ul class="chart-legend">
                                <li><span class="key ok"></span>Verified</li>
                                <li><span class="key info"></span>Implemented, not verified</li>
                                <li><span class="key none"></span>Not implemented</li>
                            </ul>
                            <div class="category-bars">
                                @foreach ($byCategory as $cat)
                                    <div class="category-row" wire:key="cb-{{ $loop->index }}">
                                        <button type="button" class="row-head small" wire:click="filterFeatures('{{ $cat['id'] }}')" title="Show the {{ $cat['total'] }} features in {{ $cat['name'] }}">
                                            <span>{{ $cat['name'] }}</span><span class="muted">{{ $cat['verified'] }} / {{ $cat['total'] }} verified</span>
                                        </button>
                                        <x-chart.stacked :title="$cat['name']" :legend="false" :segments="$cat['segments']" />
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="card-head"><h2>QA &amp; client feedback</h2><a href="{{ route('tickets.index', $project) }}" wire:navigate>All tickets</a></div>
                @if ($feedback->isEmpty())
                    <p class="muted small">No QA or client comments yet. When you pass feedback to the coding agent, it records it on the ticket.</p>
                @else
                    <x-feedback-list :comments="$feedback" />
                @endif
            </div>

            <div class="card">
                <div class="card-head"><h2>Recent agent reports</h2><a href="{{ route('reports.index', $project) }}" wire:navigate>View all</a></div>
                @if ($recentReports->isEmpty())
                    <p class="muted small">No reports yet. Coding agents send them after each task.</p>
                @else
                    <ul class="plain">
                        @foreach ($recentReports as $report)
                            <x-report-row :report="$report" wire:key="rr-{{ $report->id }}" />
                        @endforeach
                    </ul>
                @endif
            </div>

        <div class="card">
            <h2>Verification</h2>
            @php($run = $summary['latest_test_run'])
            @if ($run)
                <p class="small">
                    Latest test run <a href="{{ route('test-runs.show', $run['id']) }}" wire:navigate>#{{ $run['id'] }}</a>
                    {{ $run['created_at']->diffForHumans() }}@if ($run['git_commit']) at <code>{{ Str::limit($run['git_commit'], 10, '') }}</code>@endif:
                    <span class="badge ok">{{ $run['totals']['passed'] }} passed</span>
                    @if ($run['totals']['failed']) <span class="badge bad">{{ $run['totals']['failed'] }} failed</span> @endif
                    @if ($run['totals']['skipped']) <span class="badge">{{ $run['totals']['skipped'] }} skipped</span> @endif
                </p>
            @else
                <p class="muted small">No automated test results yet. Send them from CI or with <code>unseen verify</code>.</p>
            @endif
            @if ($summary['implemented_without_checks'])
                <p class="small"><span class="badge warn">{{ $summary['implemented_without_checks'] }} implemented {{ Str::plural('feature', $summary['implemented_without_checks']) }} without automated checks</span>
                    Test runs can only verify these once an agent links tests to them:</p>
                <ul class="plain">
                    @foreach ($withoutChecks as $feature)
                        <li class="small" wire:key="nc-{{ $feature->id }}"><a href="{{ route('features.show', $feature) }}" wire:navigate>{{ $feature->name }}</a> <x-status-badge :status="$feature->verification_status" /></li>
                    @endforeach
                </ul>
            @elseif ($summary['by_status']['implemented'] > 0)
                <p class="muted small">Every implemented feature has at least one automated check.</p>
            @endif
        </div>
        </div>

        <aside class="dash-side">
            <div class="card">
                <h2>Needs attention</h2>
                @if ($attention->isEmpty())
                    <p class="muted small">Nothing is blocked, failing, or waiting for verification.</p>
                @else
                    <ul class="plain">
                        @foreach ($attention as $feature)
                            <li wire:key="att-{{ $feature->id }}">
                                <a href="{{ route('features.show', $feature) }}" wire:navigate>{{ $feature->name }}</a>
                                <div>
                                    @if ($feature->status === \App\Enums\FeatureStatus::Blocked) <x-status-badge :status="$feature->status" /> @endif
                                    <x-status-badge :status="$feature->verification_status" />
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    @if ($summary['needs_attention'] > $attention->count())
                        <p class="muted small">Showing {{ $attention->count() }} of {{ $summary['needs_attention'] }}.</p>
                    @endif
                @endif
            </div>

            @php($tc = $summary['tickets'])
            <div class="card">
                <div class="card-head"><h2>Tickets <span class="count">{{ $tc['open'] }}</span></h2><a href="{{ route('tickets.index', $project) }}" wire:navigate>View all</a></div>
                @if ($tc['total'] === 0)
                    <p class="muted small">No tickets yet. Coding agents record the tickets they work on, and QA and client comments on them.</p>
                @else
                    <p class="small">
                        @if ($tc['sent_back']) <span class="badge bad">{{ $tc['sent_back'] }} sent back</span> @endif
                        @if ($n = $tc['by_status']['ready_for_qa']) <span class="badge info">{{ $n }} ready for QA</span> @endif
                        @if ($n = $tc['by_status']['client_review']) <span class="badge info">{{ $n }} with the client</span> @endif
                        <span class="badge ok">{{ $tc['by_status']['done'] }} done</span>
                    </p>
                    @if ($openTickets->isEmpty())
                        <p class="muted small">Nothing open.</p>
                    @else
                        <ul class="plain tickets">
                            @foreach ($openTickets as $ticket)
                                <x-ticket-row :ticket="$ticket" class="small" wire:key="ot-{{ $ticket->id }}" />
                            @endforeach
                        </ul>
                    @endif
                @endif
            </div>

            <div class="card">
                <div class="card-head"><h2>Unresolved work <span class="count">{{ $summary['open_unresolved'] }}</span></h2><a href="{{ route('unresolved.index', $project) }}" wire:navigate>View all</a></div>
                @if ($unresolved->isEmpty())
                    <p class="muted small">Nothing open. Unresolved items from reports show here until someone closes them.</p>
                @else
                    @php($wo = $summary['unresolved_waiting_on'])
                    @if ($wo['team'] || $wo['client'])
                        <p class="small decision-summary">
                            @if ($wo['team']) <a href="{{ route('unresolved.index', [$project, 'waiting' => 'team']) }}" wire:navigate class="badge bad">{{ $wo['team'] }} need your decision</a> @endif
                            @if ($wo['client']) <a href="{{ route('unresolved.index', [$project, 'waiting' => 'client']) }}" wire:navigate class="badge warn">{{ $wo['client'] }} need the client</a> @endif
                        </p>
                    @endif
                    @if ($wo['unsorted'])
                        <p class="muted small">{{ $wo['unsorted'] }} not sorted yet: <a href="{{ route('unresolved.index', $project) }}" wire:navigate>ask your agent who has to act</a>.</p>
                    @endif
                    <ul class="plain">
                        @foreach ($unresolved as $item)
                            <li class="small" wire:key="u-{{ $item['id'] }}">
                                @if ($item['waiting_on']?->needsDecision()) <span class="badge decision {{ $item['waiting_on']->tone() }}">{{ $item['waiting_on']->label() }}</span><br> @endif
                                {{ $item['decision_note'] && $item['waiting_on']?->needsDecision() ? $item['decision_note'] : Str::limit($item['text'], 220) }}
                                <div class="muted">
                                    @if ($item['report_id']) <a href="{{ route('reports.show', $item['report_id']) }}" wire:navigate>Report #{{ $item['report_id'] }}</a> · @endif
                                    {{ $item['reported_at']->diffForHumans() }}
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
            <div class="card">
                <h2>Recent changes</h2>
                @if ($recentChanges->isEmpty())
                    <p class="muted small">No status changes yet.</p>
                @else
                    <ul class="plain timeline">
                        @foreach ($recentChanges as $change)
                            <li class="small" wire:key="chg-{{ $change->id }}">
                                <a href="{{ route('features.show', $change->feature_id) }}" wire:navigate>{{ $change->feature->name }}</a>:
                                {{ $change->field === 'status' ? 'status' : 'verification' }}
                                {{ $change->from_value ? 'changed from '.str_replace('_', ' ', $change->from_value).' to' : 'set to' }}
                                <strong>{{ str_replace('_', ' ', $change->to_value) }}</strong>
                                <div class="muted">{{ $change->source->label() }}{{ $change->user ? ' ('.$change->user->name.')' : '' }} · {{ $change->created_at->diffForHumans() }}</div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </aside>
    </div>
</div>
