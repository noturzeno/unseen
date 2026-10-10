@props(['ticket'])
{{-- One ticket in a list: reference, title, status, and the latest QA or client comment. --}}
<li {{ $attributes }}>
    <div class="ticket-line">
        <span class="ticket-ref">{{ $ticket->label() }}</span>
        <a href="{{ route('tickets.show', $ticket) }}" wire:navigate>{{ $ticket->title }}</a>
        <span class="badge {{ $ticket->status->tone() }}">{{ $ticket->status->label() }}</span>
    </div>
    <div class="muted small">
        {{ $ticket->type->label() }}@if ($ticket->priority) · {{ ucfirst($ticket->priority) }} priority @endif
        @if ($ticket->feature) · <a href="{{ route('features.show', $ticket->feature_id) }}" wire:navigate>{{ $ticket->feature->name }}</a> @endif
        · {{ $ticket->comments_count }} {{ Str::plural('entry', $ticket->comments_count) }}
        · updated {{ $ticket->updated_at->diffForHumans() }}
    </div>
    @if ($ticket->latestFeedback)
        <blockquote class="feedback small">
            <span class="badge {{ $ticket->latestFeedback->author_role->tone() }}">{{ $ticket->latestFeedback->author_role->label() }}</span>
            {{ Str::limit($ticket->latestFeedback->body, 160) }}
        </blockquote>
    @endif
</li>
