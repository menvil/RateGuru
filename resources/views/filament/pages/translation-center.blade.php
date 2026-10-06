@php
    /*
     * Translation Center: one target language, every unit that needs a
     * translation, English beside the target field. The rows come from the
     * server once per target language; the browser filters them, keeps the
     * drafts and saves one row at a time (filament.pages.translation-center.
     * script). The whole screen is keyed by the target language, so choosing
     * another one draws it afresh.
     */
    $filtered = $target !== null && ($filters['query'] !== '' || $filters['section'] !== '' || $filters['mode'] !== 'all' || $filters['unit'] !== null);
@endphp

<div class="rg-admin rg-admin-screen">
    @include('filament.pages.translation-center.script')

    @if ($target === null)
        <section class="rg-admin-page-header" aria-labelledby="rg-admin-translation-center-title">
            <div class="rg-admin-page-header__text">
                <h1 id="rg-admin-translation-center-title" class="rg-admin-page-header__title">Translation Center</h1>
                <p class="rg-admin-page-header__description">Translate this project’s own content into every installed language.</p>
            </div>
        </section>

        <div class="rg-admin-screen__body">
            <div class="rg-admin-card">
                <x-admin.ui.empty-state icon="languages" title="No language to translate into">
                    Only English is installed, and English is the reference every translation is made from. A release that installs another language adds it here.
                </x-admin.ui.empty-state>
            </div>
        </div>
    @else
        <div
            wire:key="rg-admin-translation-center-{{ $target['code'] }}"
            class="rg-admin-translation-center"
            x-data="rgAdminTranslationCenter(@js($client))"
            x-on:beforeunload.window="warnBeforeLeaving($event)"
            x-bind:aria-busy="switching ? 'true' : 'false'"
        >
            <section class="rg-admin-page-header rg-admin-translation-center__header" aria-labelledby="rg-admin-translation-center-title">
                <h1 id="rg-admin-translation-center-title" class="rg-admin-sr-only">Translation Center</h1>

                <x-admin.ui.combobox
                    id="rg-admin-translation-target"
                    class="rg-admin-translation-center__target"
                    label="Target language"
                    :options="$options"
                    :value="$target['code']"
                    :search-placeholder="'Search '.count($options).' target '.\Illuminate\Support\Str::plural('language', count($options))"
                    empty="No installed language matches."
                    x-on:choose="$event.preventDefault(); chooseTarget($event.detail.value)"
                />

                {{-- The target language's figures, whatever the filters show. --}}
                <dl class="rg-admin-stats rg-admin-translation-center__stats">
                    <div class="rg-admin-stat">
                        <dt class="rg-admin-stat__label">Total items</dt>
                        <dd class="rg-admin-stat__value" x-text="figure(total)">{{ number_format($stats['total']) }}</dd>
                    </div>
                    <div class="rg-admin-stat">
                        <dt class="rg-admin-stat__label">Translated</dt>
                        <dd class="rg-admin-stat__value" x-text="figure(translated)">{{ number_format($stats['translated']) }}</dd>
                    </div>
                    <div class="rg-admin-stat">
                        <dt class="rg-admin-stat__label">Missing</dt>
                        <dd class="rg-admin-stat__value" x-text="figure(missing)">{{ number_format($stats['missing']) }}</dd>
                    </div>
                    <div class="rg-admin-stat rg-admin-translation-center__completion">
                        <dt class="rg-admin-stat__label">Completion</dt>
                        <dd class="rg-admin-translation-center__completion-figure">
                            <span class="rg-admin-progress rg-admin-progress--summary rg-admin-translation-center__completion-bar" aria-hidden="true">
                                <span
                                    @class(['rg-admin-progress__bar', 'rg-admin-progress__bar--complete' => $stats['percentage'] === 100])
                                    style="width: {{ $stats['percentage'] }}%"
                                    x-bind:class="{ 'rg-admin-progress__bar--complete': percentage === 100 }"
                                    x-bind:style="{ width: percentage + '%' }"
                                ></span>
                            </span>
                            <span class="rg-admin-translation-center__percentage" x-text="percentage + '%'">{{ $stats['percentage'] }}%</span>
                        </dd>
                    </div>
                </dl>
            </section>

            <div class="rg-admin-screen__body">
                <div class="rg-admin-table rg-admin-translation-center__card">
                    <div class="rg-admin-toolbar">
                        <x-admin.ui.search-field
                            class="rg-admin-toolbar__search"
                            placeholder="Search source, key or translation"
                            name="q"
                            id="rg-admin-translation-search"
                            :value="$filters['query']"
                            x-model="query"
                        />
                        <x-admin.ui.filter-dropdown
                            id="rg-admin-translation-section"
                            label="Section"
                            :options="$sections"
                            :value="$filters['section']"
                            x-model="section"
                        />
                        <x-admin.ui.segmented
                            label="Show"
                            :options="['missing' => 'Missing only', 'all' => 'All']"
                            :value="$filters['mode']"
                            x-model="mode"
                        />
                        {{-- Always present, so a screen reader hears the new count while focus stays in the field. --}}
                        <span class="rg-admin-toolbar__count" role="status" x-text="resultText">{{ number_format(count($rows)) }} of {{ number_format(count($rows)) }} items</span>
                    </div>

                    <div x-cloak x-show="dirtyCount > 0">
                        <x-admin.ui.inline-notice tone="info" strip>
                            <span x-text="unsavedText"></span>
                            <x-slot:actions>
                                <x-admin.ui.button variant="ghost" size="sm" x-on:click="discardAll()">Discard all</x-admin.ui.button>
                            </x-slot:actions>
                        </x-admin.ui.inline-notice>
                    </div>

                    @if ($rows === [])
                        <x-admin.ui.empty-state icon="circle-check" tone="success" title="Nothing to translate">
                            None of the project’s content has English text to translate yet.
                        </x-admin.ui.empty-state>
                    @else
                        {{-- Opened with filters or a link to one item: the rows wait for the browser to apply them rather than flash unfiltered. --}}
                        <div class="rg-admin-translation-center__grid" role="table" aria-label="{{ $target['label'] }} translations" @if ($filtered) x-cloak @endif>
                            <div class="rg-admin-translation-row rg-admin-translation-row--head" role="row">
                                <div class="rg-admin-translation-row__cell" role="columnheader">Item</div>
                                <div class="rg-admin-translation-row__cell" role="columnheader">English · reference</div>
                                <div class="rg-admin-translation-row__cell" role="columnheader" lang="{{ $target['code'] }}">{{ $target['native'] }} · {{ $target['code'] }}</div>
                            </div>

                            @foreach ($rows as $row)
                                @include('filament.pages.translation-center.row', ['row' => $row, 'target' => $target])
                            @endforeach
                        </div>

                        <div x-cloak x-show="shown === 0">
                            <template x-if="mode === 'missing' && query.trim() === ''">
                                <x-admin.ui.empty-state icon="check" tone="success" title="Nothing missing here">
                                    Every item in this view has a stored {{ $target['label'] }} translation.
                                </x-admin.ui.empty-state>
                            </template>
                            <template x-if="! (mode === 'missing' && query.trim() === '')">
                                <x-admin.ui.empty-state title="No items match these filters">
                                    Try a different search or section, or show all items.
                                    <x-slot:action>
                                        <x-admin.ui.button size="sm" x-on:click="clearFilters()">Clear filters</x-admin.ui.button>
                                    </x-slot:action>
                                </x-admin.ui.empty-state>
                            </template>
                        </div>
                    @endif

                    <div class="rg-admin-translation-center__footer">
                        Application interface strings are release-managed and not listed here.
                    </div>
                </div>
            </div>

            @include('filament.pages.translation-center.context')

            <template x-if="pendingTarget">
                <x-admin.ui.confirm-dialog
                    id="rg-admin-translation-switch"
                    tone="warning"
                    title="Discard unsaved translations?"
                    x-on:dismiss="pendingTarget = null"
                >
                    <p x-text="switchText"></p>
                    <x-slot:actions>
                        <x-admin.ui.button x-on:click="dismiss()" autofocus>Keep editing</x-admin.ui.button>
                        <x-admin.ui.button variant="primary" x-on:click="hide(); confirmSwitch()">Discard and switch</x-admin.ui.button>
                    </x-slot:actions>
                </x-admin.ui.confirm-dialog>
            </template>
        </div>
    @endif
</div>
