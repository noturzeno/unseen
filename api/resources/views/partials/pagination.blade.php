{{-- Livewire pagination styled for app.css. Use as $paginator->links('partials.pagination'). --}}
@if ($paginator->hasPages())
    @php($page = $paginator->getPageName())
    <nav class="pager" aria-label="Pagination">
        <span class="muted small">
            Showing {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} of {{ $paginator->total() }}
        </span>
        <span class="actions">
            <button class="btn" type="button" wire:click="previousPage('{{ $page }}')" @disabled($paginator->onFirstPage())>Previous</button>
            <button class="btn" type="button" wire:click="nextPage('{{ $page }}')" @disabled(! $paginator->hasMorePages())>Next</button>
        </span>
    </nav>
@endif
