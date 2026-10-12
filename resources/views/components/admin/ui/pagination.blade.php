@props([
    'paginator',
    'noun' => 'items',
    'note' => null,
    'action' => null,
    'label' => 'Pagination',
])

@php
    /*
     * NAV-04. The footer of a list a server reads one page at a time: the
     * range shown (“26–50 of 1,231 calls”), then the pages — the first, the
     * last, and the ones either side of the current page, with a gap where
     * pages are left out — between Previous and Next, which are disabled at
     * the ends.
     *
     * paginator is a LengthAwarePaginator. With action, each page is a button
     * that calls that Livewire method with the page number, and nothing
     * reloads; without it, each page is a link to the paginator's own URL.
     *
     *     <x-admin.ui.pagination :paginator="$calls" noun="calls" note="newest first" action="goToCallsPage" />
     */
    if (! $paginator instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator) {
        throw new InvalidArgumentException('Admin pagination needs a length-aware paginator.');
    }

    $current = $paginator->currentPage();
    $last = $paginator->lastPage();
    $pages = array_values(array_unique(array_filter([1, $current - 1, $current, $current + 1, $last], fn (int $page): bool => $page >= 1 && $page <= $last)));
    sort($pages);
    // The range from the page and its size, so it reads the same whether or not the page's items are loaded.
    $first = min($paginator->total(), ($current - 1) * $paginator->perPage() + 1);
    $range = $paginator->total() === 0
        ? 'No '.$noun
        : number_format($first).'–'.number_format(min($paginator->total(), $current * $paginator->perPage())).' of '.number_format($paginator->total()).' '.$noun;
@endphp

<nav {{ $attributes->class(['rg-admin-pagination']) }} aria-label="{{ $label }}">
    <span class="rg-admin-pagination__range">{{ $range }}@if ($note !== null && $paginator->total() > 0) · {{ $note }}@endif</span>
    <ul class="rg-admin-pagination__pages">
        <li>
            @if ($action !== null)
                <x-admin.ui.icon-button icon="chevron-left" label="Previous page" variant="ghost" size="sm" :disabled="$current <= 1" wire:click="{{ $action }}({{ max(1, $current - 1) }})" />
            @else
                <x-admin.ui.icon-button icon="chevron-left" label="Previous page" variant="ghost" size="sm" :disabled="$current <= 1" :href="$current > 1 ? $paginator->url($current - 1) : null" />
            @endif
        </li>
        @foreach ($pages as $index => $page)
            @if ($index > 0 && $page - $pages[$index - 1] > 1)
                <li class="rg-admin-pagination__gap" aria-hidden="true">…</li>
            @endif
            <li>
                @if ($action !== null)
                    <button
                        type="button"
                        class="rg-admin-pagination__page"
                        wire:click="{{ $action }}({{ $page }})"
                        aria-label="Page {{ $page }}"
                        @if ($page === $current) aria-current="page" @endif
                    >{{ number_format($page) }}</button>
                @else
                    <a
                        class="rg-admin-pagination__page"
                        href="{{ $paginator->url($page) }}"
                        aria-label="Page {{ $page }}"
                        @if ($page === $current) aria-current="page" @endif
                    >{{ number_format($page) }}</a>
                @endif
            </li>
        @endforeach
        <li>
            @if ($action !== null)
                <x-admin.ui.icon-button icon="chevron-right" label="Next page" variant="ghost" size="sm" :disabled="$current >= $last" wire:click="{{ $action }}({{ min($last, $current + 1) }})" />
            @else
                <x-admin.ui.icon-button icon="chevron-right" label="Next page" variant="ghost" size="sm" :disabled="$current >= $last" :href="$current < $last ? $paginator->url($current + 1) : null" />
            @endif
        </li>
    </ul>
</nav>
