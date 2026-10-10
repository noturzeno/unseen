<span class="proceed" @if ($waiting) wire:poll.2s @endif>
    @if (! $run || in_array($run->status, ['launched', 'failed', 'expired'], true))
        <button @class(['btn', 'proceed-btn', 'sm' => $small]) type="button" wire:click="proceed" wire:loading.attr="disabled"
            title="Open Claude Code in {{ $localPath ?: 'the folder where your `unseen runner` is running' }} and run this prompt">
            {{ $run ? ($label ? $label.' again' : 'Proceed again') : ($label ?? 'Proceed') }}
        </button>
    @endif

    @if ($run)
        <span @class(['proceed-status', 'small', 'ok' => $run->status === 'launched', 'bad' => in_array($run->status, ['failed', 'expired'], true)]) role="status">
            @switch ($run->status)
                @case('queued')
                    @if ($stale)
                        Waiting… Your runner isn't running.
                        <a class="btn sm" href="{{ $workspace->startRunnerUrl() }}">Start runner</a>
                        <a href="{{ route('projects.edit', $project) }}#project-folder" wire:navigate>Setup</a>
                    @else
                        Sending to your computer…
                    @endif
                    @break
                @case('claimed')
                    Opening Claude Code on {{ $run->runner_name }}…
                    @break
                @case('launched')
                    Opened in Claude Code ✓
                    @break
                @case('failed')
                    Couldn't open it: {{ Str::limit($run->error ?: 'unknown error', 160) }}
                    @break
                @case('expired')
                    No runner picked it up in time.
                    @break
            @endswitch
        </span>
    @endif
</span>
