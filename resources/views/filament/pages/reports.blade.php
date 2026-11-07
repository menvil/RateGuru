@php
    /*
     * Reports (ListReports). The tabs, then the list and the open report's
     * chain. Where the main column leaves 1048 for the list, the chain docks
     * beside it as the 448 panel and the list condenses to four columns;
     * where it does not, the chain floats over the list and the list keeps
     * every column, scrolling inside its card (TBL-01).
     *
     * Opened wide with no report chosen, the first report of the list opens.
     * J and K step to the next and the previous report from anywhere but a
     * field or a dialog.
     */
    $total = $paginator->total();
    $firstId = $rows[0]['id'] ?? null;
@endphp

<div
    class="rg-admin rg-admin-screen"
    x-data="{
        wide: false,
        query: null,
        init() {
            // The main column is the window less the sidebar: 300 from 1280, the 68 rail below.
            this.query = window.matchMedia('(min-width: 1348px), (min-width: 1116px) and (max-width: 1279px)')
            this.wide = this.query.matches
            this.query.addEventListener('change', (event) => (this.wide = event.matches))

            if (this.wide && $wire.report === null && @js($firstId) !== null) {
                $wire.select(@js($firstId))
            }
        },
        step(event, direction) {
            const field = event.target.closest('input, textarea, select, [contenteditable]')

            if (field || event.metaKey || event.ctrlKey || event.altKey || document.querySelector('.rg-admin-dialog')) {
                return
            }

            $wire.step(direction)
        },
    }"
    x-on:keydown.j.window="step($event, 1)"
    x-on:keydown.k.window="step($event, -1)"
    x-bind:class="{ 'rg-admin-reports--docked': wide && $wire.report !== null }"
>
    <section class="rg-admin-page-header" aria-labelledby="rg-admin-reports-title">
        <div class="rg-admin-page-header__text rg-admin-reports__intro">
            <h1 id="rg-admin-reports-title" class="rg-admin-page-header__title">Reports</h1>
            <p class="rg-admin-page-header__description">
                Each report points to one post, comment or person, and its author. Acting on the content and closing the report are separate steps.
            </p>
        </div>
        <dl class="rg-admin-stats">
            <div class="rg-admin-stat">
                <dt class="rg-admin-stat__label">Open</dt>
                <dd class="rg-admin-stat__value">{{ $stats['open'] }}</dd>
            </div>
            <div class="rg-admin-stat">
                <dt class="rg-admin-stat__label">Targets</dt>
                <dd class="rg-admin-stat__value">{{ $stats['targets'] }}</dd>
            </div>
            <div class="rg-admin-stat">
                <dt class="rg-admin-stat__label">Oldest open</dt>
                <dd class="rg-admin-stat__value">{{ $stats['oldest'] }}</dd>
            </div>
        </dl>
    </section>

    <div class="rg-admin-screen__body">
        <x-admin.ui.tabs label="Reports by status" :active="$status" :items="$tabs" />

        <div class="rg-admin-reports__layout">
            <div class="rg-admin-table">
                <div class="rg-admin-toolbar">
                    <x-admin.ui.search-field
                        class="rg-admin-reports__search"
                        placeholder="Search content, reporter or ID"
                        name="q"
                        id="rg-admin-reports-search"
                        :value="$q"
                        wire:model.live.debounce.300ms="q"
                        clearable
                    />
                    <x-admin.ui.filter-dropdown label="Type" id="rg-admin-reports-type" :options="$types" :value="$type" wire:model.live="type" />
                    {{-- Always present, so a screen reader hears the new count while focus stays in the field. --}}
                    <span class="rg-admin-toolbar__count" role="status">{{ number_format($total) }} {{ \Illuminate\Support\Str::plural('report', $total) }}{{ $filtered ? ' match' : '' }}</span>
                </div>

                @if ($rows !== [])
                    <div class="rg-admin-table__scroll">
                        <div class="rg-admin-table__grid rg-admin-reports__grid" role="table" aria-label="Reports">
                            <div class="rg-admin-table__row rg-admin-table__row--head" role="row">
                                <div class="rg-admin-table__cell rg-admin-reports__full" role="columnheader">Type</div>
                                <div class="rg-admin-table__cell" role="columnheader">Target</div>
                                <div class="rg-admin-table__cell" role="columnheader"><span class="rg-admin-reports__full">Reason</span><span class="rg-admin-reports__docked">Reason · reporter</span></div>
                                <div class="rg-admin-table__cell rg-admin-reports__full" role="columnheader">Reporter</div>
                                <div class="rg-admin-table__cell" role="columnheader">Status</div>
                                <div class="rg-admin-table__cell" role="columnheader">Created</div>
                                <div class="rg-admin-table__cell rg-admin-table__cell--end rg-admin-reports__full" role="columnheader">Actions</div>
                            </div>

                            @foreach ($rows as $row)
                                @include('filament.pages.reports.row', ['row' => $row])
                            @endforeach
                        </div>
                    </div>

                    <x-admin.ui.pagination :paginator="$paginator" noun="reports" :note="$status === 'open' ? 'oldest first' : 'newest first'" action="goToPage" label="Reports pages" />
                @elseif ($status === 'open' && ! $filtered)
                    <x-admin.ui.empty-state icon="circle-check" tone="success" title="No open reports">
                        Every report has been resolved or ignored. New reports appear here when you open the page again.
                    </x-admin.ui.empty-state>
                @else
                    <x-admin.ui.empty-state icon="search-x" title="No reports here">
                        Nothing matches the current filters.
                    </x-admin.ui.empty-state>
                @endif
            </div>

            @if ($detail !== null)
                @include('filament.pages.reports.panel')
            @endif
        </div>
    </div>

    @if ($detail !== null && $detail['confirmation'] !== null)
        @include('filament.pages.reports.confirmation')
    @endif
</div>
