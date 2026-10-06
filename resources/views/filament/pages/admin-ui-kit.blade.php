@php
    $allSearch = collect($groups)->pluck('search')->flatten()->all();
@endphp

<div
    class="rg-admin rg-admin-kit"
    x-data="{
        q: '',
        all: @js($allSearch),
        matches(text) {
            const needle = this.q.trim().toLowerCase();

            return needle === '' || text.toLowerCase().includes(needle);
        },
        get anyMatch() {
            return this.all.some((text) => this.matches(text));
        },
    }"
>
    <div class="rg-admin-kit__layout">
        <aside class="rg-admin-kit__index" aria-label="Dev UI kit index">
            <div class="rg-admin-kit__index-head">
                <p class="rg-admin-kit__index-title">Dev UI kit</p>
                <p class="rg-admin-kit__index-subtitle">RateGuru Admin v2 · {{ $total }} elements</p>
            </div>
            <div class="rg-admin-kit__index-search">
                <x-admin.ui.search-field placeholder="Find element or ID" name="kit-search" id="rg-admin-kit-search" x-model="q" />
            </div>
            <nav class="rg-admin-kit__index-nav" aria-label="Elements">
                @foreach ($groups as $group)
                    <div x-show="@js($group['search']).some((text) => matches(text))">
                        <p class="rg-admin-kit__index-label" id="rg-admin-kit-index-{{ $group['key'] }}">{{ $group['label'] }}</p>
                        <ul class="rg-admin-kit__index-group" aria-labelledby="rg-admin-kit-index-{{ $group['key'] }}">
                            @foreach ($group['ids'] as $id)
                                <li x-show="matches(@js($specs[$id]['search']))">
                                    <a class="rg-admin-kit__index-link" href="#{{ $id }}">
                                        <span class="rg-admin-kit__index-id">{{ $id }}</span>
                                        <span class="rg-admin-kit__index-name">{{ $specs[$id]['name'] }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
                <p class="rg-admin-kit__empty" x-show="! anyMatch" x-cloak>
                    No element matches “<span x-text="q.trim()"></span>”.
                </p>
            </nav>
        </aside>

        <main class="rg-admin-kit__main">
            <header class="rg-admin-kit__header">
                <p class="rg-admin-kit__overline">RateGuru Admin v2 · reference for developers</p>
                <h1 class="rg-admin-kit__title">Dev UI kit</h1>
                <p class="rg-admin-kit__lead">
                    Every element the admin uses, each with a stable ID, a live specimen, measurements, tokens and the
                    Blade it is built from. Quote the ID in tickets and reviews, link straight to it, and build each
                    screen from these components.
                </p>
                <div class="rg-admin-kit__search--narrow">
                    <x-admin.ui.search-field placeholder="Find element or ID" name="kit-search-narrow" id="rg-admin-kit-search-narrow" x-model="q" />
                </div>
            </header>

            <x-admin.ui.card flush>
                <div class="rg-admin-kit__facts">
                    <div class="rg-admin-kit__fact">
                        <div class="rg-admin-section-label"><span class="rg-admin-section-label__text">Reference ID</span></div>
                        <p class="rg-admin-kit__fact-value">TBL-01</p>
                        <p class="rg-admin-kit__fact-note">Group prefix and number, the same as in the design reference. IDs never change or get reused.</p>
                    </div>
                    <div class="rg-admin-kit__fact">
                        <div class="rg-admin-section-label"><span class="rg-admin-section-label__text">Direct link</span></div>
                        <p class="rg-admin-kit__fact-value">/admin/dev/ui-kit#TBL-01</p>
                        <p class="rg-admin-kit__fact-note">Every ID chip below is a link to its own card.</p>
                    </div>
                    <div class="rg-admin-kit__fact">
                        <div class="rg-admin-section-label"><span class="rg-admin-section-label__text">Source</span></div>
                        <p class="rg-admin-kit__fact-note"><span class="rg-admin-kit__source-component">Blade component</span> · x-admin.ui.*, use it as is</p>
                        <p class="rg-admin-kit__fact-note"><span class="rg-admin-kit__source-primitive">CSS primitive</span> · .rg-admin-* classes, compose with your own markup</p>
                    </div>
                </div>
            </x-admin.ui.card>

            @foreach ($groups as $group)
                <div class="rg-admin-kit__group" x-show="@js($group['search']).some((text) => matches(text))">
                    <h2 class="rg-admin-kit__group-title">{{ $group['label'] }}</h2>
                    <p class="rg-admin-kit__group-description">{{ $group['description'] }}</p>
                </div>

                @include('filament.pages.admin-ui-kit.'.$group['key'])
            @endforeach

            <div x-show="! anyMatch" x-cloak>
                <x-admin.ui.card flush>
                    <x-admin.ui.empty-state title="No element matches this search" :heading-level="2">
                        Search by ID, such as TBL-01, or by name, such as Badge.
                    </x-admin.ui.empty-state>
                </x-admin.ui.card>
            </div>
        </main>
    </div>

    {{-- The kit stands outside the admin shell, which draws the stack on every other page, so it has its own for FBK-01. --}}
    <x-admin.ui.toast-stack />
</div>
