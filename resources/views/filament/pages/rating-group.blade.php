@php
    /*
     * One rating group (CreateRatingGroup, EditRatingGroup): the header band
     * with what is saved, the settings card beside the check of its active
     * options and its translations, and — once it exists — its options.
     *
     * The settings are a draft until Save in the top bar; the browser tells
     * the top bar whether anything is unsaved and whether Save may run, from
     * the draft against `saved` (filament.pages.rating-group.script).
     */
    $ordinals = ['first', 'second', 'third', 'fourth', 'fifth', 'sixth', 'seventh', 'eighth', 'ninth', 'tenth'];
    $savedActive = (bool) $saved['active'];
@endphp

<div
    class="rg-admin rg-admin-screen"
    x-data
    x-effect="$store.ratingGroupEditor.dirty = rgAdminRatingGroupDirty($wire); $store.ratingGroupEditor.blocked = $wire.settingsBlocked"
>
    @include('filament.pages.rating-group.script')

    <section class="rg-admin-page-header" aria-labelledby="rg-admin-rating-group-title">
        <div class="rg-admin-page-header__text">
            <div class="rg-admin-rating-group__title">
                <h1 id="rg-admin-rating-group-title" class="rg-admin-page-header__title">{{ $creating ? 'New rating group' : $group->label }}</h1>
                <x-admin.ui.badge :tone="$savedActive ? 'success' : 'neutral'" :dot="$savedActive">{{ $savedActive ? 'Active' : 'Inactive' }}</x-admin.ui.badge>
            </div>
            <p class="rg-admin-page-header__description rg-admin-rating-group__meta">
                @if ($creating)
                    <span>Starts inactive. Its options are added once it is created.</span>
                @else
                    <span>Key <span class="rg-admin-rating-group__key">{{ $group->key }}</span></span>
                    <span class="rg-admin-rating-group__divider" aria-hidden="true"></span>
                    <span>{{ number_format($votes) }} {{ \Illuminate\Support\Str::plural('vote', $votes) }}</span>
                    <span class="rg-admin-rating-group__divider" aria-hidden="true"></span>
                    <span>{{ $position === null ? 'Not shown on post pages' : 'Shown '.($ordinals[$position - 1] ?? '#'.$position).' on post pages' }}</span>
                @endif
            </p>
        </div>
        <dl class="rg-admin-stats">
            <div class="rg-admin-stat">
                <dt class="rg-admin-stat__label">Active options</dt>
                <dd class="rg-admin-stat__value">{{ number_format($active) }}</dd>
            </div>
            <div class="rg-admin-stat">
                <dt class="rg-admin-stat__label">Allowed range</dt>
                <dd class="rg-admin-stat__value">{{ $saved['min'] }}–{{ $saved['max'] }}</dd>
            </div>
        </dl>
    </section>

    <div class="rg-admin-screen__body rg-admin-rating-group__body">
        <div class="rg-admin-rating-group__cards">
            @include('filament.pages.rating-group.settings')

            <x-admin.ui.card>
                @include('filament.pages.rating-group.check')

                @unless ($creating)
                    @include('filament.pages.rating-group.translations')
                @endunless
            </x-admin.ui.card>
        </div>

        @unless ($creating)
            @include('filament.pages.rating-group.options')
        @endunless
    </div>

    @if (! $creating && $drawer !== null)
        @include('filament.pages.rating-group.option-drawer')
    @endif

    @if (! $creating && $confirmation !== null)
        @include('filament.pages.rating-group.confirmation', ['row' => $confirmation])
    @endif
</div>
