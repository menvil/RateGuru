@php
    /*
     * One table for every installed language, however many there are: the
     * rows come from the data, never from a branch per language. Header and
     * rows share this grid; below its minimum width the card scrolls.
     */
    $columns = '--rg-admin-table-columns: minmax(220px, 1fr) 90px 150px 180px 190px 110px 130px; --rg-admin-table-min-width: 1060px';
@endphp

<div class="rg-admin rg-admin-screen">
    <section class="rg-admin-page-header" aria-labelledby="rg-admin-languages-title">
        <div class="rg-admin-page-header__text">
            <h1 id="rg-admin-languages-title" class="rg-admin-page-header__title">Languages</h1>
            <p class="rg-admin-page-header__description">
                Manage which installed languages are available to visitors and monitor translation coverage.
            </p>
        </div>
        <dl class="rg-admin-stats">
            <div class="rg-admin-stat">
                <dt class="rg-admin-stat__label">Installed</dt>
                <dd class="rg-admin-stat__value">{{ number_format($stats['installed']) }}</dd>
            </div>
            <div class="rg-admin-stat">
                <dt class="rg-admin-stat__label">Enabled</dt>
                <dd class="rg-admin-stat__value">{{ number_format($stats['enabled']) }}</dd>
            </div>
            <div class="rg-admin-stat">
                <dt class="rg-admin-stat__label">Project translations</dt>
                <dd class="rg-admin-stat__value">{{ $stats['translated'] }}%</dd>
            </div>
            <div class="rg-admin-stat">
                <dt class="rg-admin-stat__label">Missing</dt>
                <dd class="rg-admin-stat__value">{{ number_format($stats['missing']) }}</dd>
            </div>
        </dl>
    </section>

    <div class="rg-admin-screen__body">
        <x-admin.ui.inline-notice tone="info">
            Application translations ship with the release and must be valid before a language can be enabled.
            Project content can be translated independently; missing project translations fall back to {{ $referenceLabel }}.
        </x-admin.ui.inline-notice>

        <x-admin.ui.tabs label="Language status" :active="$status" :items="$tabs" />

        <div class="rg-admin-table">
            <div class="rg-admin-table__scroll">
                <div class="rg-admin-table__grid" role="table" aria-label="Installed languages" style="{{ $columns }}">
                    <div class="rg-admin-table__row rg-admin-table__row--head" role="row">
                        <div class="rg-admin-table__cell" role="columnheader">Language</div>
                        <div class="rg-admin-table__cell" role="columnheader">Locale</div>
                        <div class="rg-admin-table__cell" role="columnheader">Status</div>
                        <div class="rg-admin-table__cell" role="columnheader">Application</div>
                        <div class="rg-admin-table__cell" role="columnheader">Project content</div>
                        <div class="rg-admin-table__cell" role="columnheader">Missing</div>
                        <div class="rg-admin-table__cell rg-admin-table__cell--end" role="columnheader">Actions</div>
                    </div>

                    @foreach ($rows as $row)
                        @include('filament.pages.languages.row', ['row' => $row])
                    @endforeach
                </div>
            </div>

            {{-- The default language is always enabled, so only these two tabs can be empty. --}}
            @if ($rows === [] && $status === 'incomplete')
                <x-admin.ui.empty-state icon="circle-check" tone="success" title="Every language is complete">
                    Every application catalog is valid and every piece of project content has a translation.
                </x-admin.ui.empty-state>
            @elseif ($rows === [] && $status === 'disabled')
                <x-admin.ui.empty-state icon="globe" title="Every installed language is enabled">
                    A language that is disabled is listed here, ready to be enabled again.
                </x-admin.ui.empty-state>
            @endif
        </div>
    </div>

    @if ($confirmation !== null)
        @include('filament.pages.languages.confirmation')
    @endif

    @if ($missing !== null)
        @include('filament.pages.languages.missing')
    @endif
</div>
