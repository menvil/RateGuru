@once
<style>
.rg-pgn-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    height: 26px;
    min-width: 26px;
    padding: 0 5px;
    border-radius: var(--rg-radius-sm);
    border: 1px solid var(--rg-border-2);
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    transition: background-color 150ms, color 150ms;
    background-color: var(--rg-card);
    color: var(--rg-text-2);
    text-decoration: none;
}
.rg-pgn-btn:hover {
    background-color: var(--rg-card-2);
    color: var(--rg-text);
}
.rg-pgn-active {
    border-color: var(--rg-accent-border);
    background-color: var(--rg-accent-soft);
    color: var(--rg-accent-2);
    cursor: default;
}
.rg-pgn-active:hover {
    background-color: var(--rg-accent-soft);
    color: var(--rg-accent-2);
}
.rg-pgn-off {
    border-color: var(--rg-border);
    background-color: var(--rg-card);
    color: var(--rg-muted);
    opacity: 0.35;
    cursor: not-allowed;
    pointer-events: none;
}
.rg-pgn-ellipsis {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    height: 26px;
    width: 18px;
    font-size: 11px;
    color: var(--rg-muted);
    user-select: none;
}
</style>
@endonce

@if ($paginator->hasPages())
    @php
        $last    = $paginator->lastPage();
        $current = $paginator->currentPage();

        if ($last <= 7) {
            $pages = range(1, $last);
        } else {
            // Always show first 3 and last 2; show current page if it falls outside those groups
            $set = collect([1, 2, 3, $current, $last - 1, $last])
                ->filter(fn($p) => $p >= 1 && $p <= $last)
                ->unique()->sort()->values();

            // Fill gaps of exactly one missing page (avoids lonely ellipsis like "3 … 5")
            $filled = collect();
            $arr = $set->toArray();
            foreach ($arr as $i => $page) {
                $filled->push($page);
                if (isset($arr[$i + 1]) && $arr[$i + 1] - $page === 2) {
                    $filled->push($page + 1);
                }
            }

            $pages = $filled->unique()->sort()->values()->toArray();
        }
    @endphp

    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}"
         class="flex flex-wrap items-center justify-center gap-1">

        {{-- Previous arrow --}}
        @if ($paginator->onFirstPage())
            <span class="rg-pgn-btn rg-pgn-off" aria-disabled="true" aria-label="{{ __('pagination.previous') }}">
                <svg width="10" height="10" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
            </span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="rg-pgn-btn" aria-label="{{ __('pagination.previous') }}">
                <svg width="10" height="10" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
            </a>
        @endif

        {{-- Page numbers --}}
        @php $prev = null; @endphp
        @foreach ($pages as $page)
            @if ($prev !== null && $page - $prev > 1)
                <span class="rg-pgn-ellipsis" aria-hidden="true">…</span>
            @endif

            @if ($page === $current)
                <span class="rg-pgn-btn rg-pgn-active" aria-current="page">{{ $page }}</span>
            @else
                <a href="{{ $paginator->url($page) }}"
                   class="rg-pgn-btn"
                   aria-label="{{ __('Go to page :page', ['page' => $page]) }}">{{ $page }}</a>
            @endif

            @php $prev = $page; @endphp
        @endforeach

        {{-- Next arrow --}}
        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="rg-pgn-btn" aria-label="{{ __('pagination.next') }}">
                <svg width="10" height="10" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
            </a>
        @else
            <span class="rg-pgn-btn rg-pgn-off" aria-disabled="true" aria-label="{{ __('pagination.next') }}">
                <svg width="10" height="10" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
            </span>
        @endif

    </nav>
@endif
