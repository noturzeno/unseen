@props(['comments'])
{{-- Latest QA and client comments, each linked to its ticket. --}}
<ul class="plain">
    @foreach ($comments as $comment)
        <li class="small" wire:key="fb-{{ $comment->id }}">
            <span class="badge {{ $comment->author_role->tone() }}">{{ $comment->author_role->label() }}</span>
            @if ($comment->author_name) <strong>{{ $comment->author_name }}</strong> @endif
            @if ($comment->to_status) <span class="muted">→ {{ $comment->to_status->label() }}</span> @endif
            <div class="feedback-body">{{ Str::limit($comment->body, 220) }}</div>
            <div class="muted">
                <a href="{{ route('tickets.show', $comment->ticket_id) }}" wire:navigate>{{ $comment->ticket->label() }} {{ Str::limit($comment->ticket->title, 60) }}</a>
                · {{ $comment->created_at->diffForHumans() }}
            </div>
        </li>
    @endforeach
</ul>
