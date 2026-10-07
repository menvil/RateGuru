@php
    /*
     * Translation Center: one target language, every unit that needs a
     * translation, English beside the target field. The rows come from the
     * server once per target language; the browser filters them, keeps the
     * drafts — AI suggestions among them — and saves one row at a time
     * (filament.pages.translation-center.script). The whole screen is keyed by
     * the target language, so choosing another one draws it afresh.
     */
    $filtered = $target !== null && ($filters['query'] !== '' || $filters['section'] !== '' || $filters['mode'] !== 'all' || $filters['unit'] !== null);
    // A background generation the page opens with: running, or with suggestions waiting, it replaces Generate missing.
    $generation = $target !== null ? $client['generation'] : null;
    $generationBusy = $generation !== null && ($generation['status'] !== 'completed' || $generation['counts']['ready'] > 0);
    // Dialog titles that count as the page does.
    $generateTitle = new \Illuminate\Support\HtmlString('<span x-text="\'Generate \' + figure(missing) + (missing === 1 ? \' suggestion?\' : \' suggestions?\')"></span>');
    $saveAllTitle = new \Illuminate\Support\HtmlString('<span x-text="\'Save \' + figure(saveAllUnits.length) + (saveAllUnits.length === 1 ? \' generated translation?\' : \' generated translations?\')"></span>');
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

                {{-- Every missing translation of the language, whatever the filters show; then the suggestions, saved together. --}}
                <div class="rg-admin-translation-center__actions">
                    <x-admin.ui.button
                        icon="sparkles"
                        :disabled="$stats['missing'] === 0"
                        :x-cloak="$generationBusy"
                        x-show="offersGenerate"
                        x-bind:disabled="missing === 0 || generationBusy"
                        x-on:click="askToGenerate()"
                    >
                        <span x-text="'Generate missing (' + figure(missing) + ')'">Generate missing ({{ number_format($stats['missing']) }})</span>
                    </x-admin.ui.button>
                    <x-admin.ui.button
                        variant="primary"
                        icon="check-check"
                        x-cloak
                        x-show="readyCount > 0"
                        x-bind:disabled="saveAllUnits.length === 0 || generationBusy"
                        x-on:click="askToSaveAll()"
                    >
                        <span x-text="'Save all generated (' + figure(saveAllUnits.length) + ')'">Save all generated</span>
                    </x-admin.ui.button>
                </div>
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
                            clearable
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

                    {{-- Background generation: progress while it runs, then what it produced. Said aloud as it changes. --}}
                    <div data-strip="generation" x-cloak x-show="generation !== null">
                        <x-admin.ui.inline-notice tone="info" icon="sparkles" strip>
                            <span class="rg-admin-translation-center__generation" role="status">
                                <span class="rg-admin-translation-center__generation-text">
                                    <span class="rg-admin-translation-row__spinner" aria-hidden="true" x-show="generationRunning"></span>
                                    <span x-text="generationText"></span>
                                </span>
                                <span class="rg-admin-translation-center__generation-hint" x-text="generationHint"></span>
                            </span>
                            <x-slot:actions>
                                <x-admin.ui.button variant="ghost" size="sm" x-show="readyCount > 0" x-bind:disabled="generationBusy" x-on:click="discardAllGenerated()">Discard generated</x-admin.ui.button>
                            </x-slot:actions>
                        </x-admin.ui.inline-notice>
                    </div>

                    {{-- The drafts only this page holds, AI suggestions counted apart; sparkles once one of them is AI's. --}}
                    <div data-strip="edits" x-cloak x-show="volatileDirtyCount > 0 && aiCount === 0">
                        <x-admin.ui.inline-notice tone="info" strip>
                            <span x-text="unsavedText"></span>
                            <x-slot:actions>
                                <x-admin.ui.button variant="ghost" size="sm" x-on:click="discardAll()">Discard all</x-admin.ui.button>
                            </x-slot:actions>
                        </x-admin.ui.inline-notice>
                    </div>
                    <div data-strip="ai" x-cloak x-show="aiCount > 0">
                        <x-admin.ui.inline-notice tone="info" icon="sparkles" strip>
                            <span x-text="unsavedText"></span>
                            <x-slot:actions>
                                <x-admin.ui.button variant="ghost" size="sm" x-on:click="discardAll()">Discard all</x-admin.ui.button>
                            </x-slot:actions>
                        </x-admin.ui.inline-notice>
                    </div>

                    {{-- AI translate and Regenerate, said aloud: started, and ready. A failure is a toast, which speaks for itself. --}}
                    <span class="rg-admin-sr-only" role="status" x-text="announcement"></span>

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

            <template x-if="pendingGenerate">
                <x-admin.ui.confirm-dialog
                    id="rg-admin-translation-generate"
                    icon="sparkles"
                    :title="$generateTitle"
                    x-on:dismiss="pendingGenerate = false"
                >
                    <p>AI translates every missing item into {{ $target['native'] }}, whatever the filters show, using each item’s context, limits and existing translations. It runs in the background: you can leave this page meanwhile.</p>
                    <x-slot:details>
                        <x-admin.ui.inline-notice tone="info">Suggestions are not saved. They become project data only when you save them, one by one or with Save all generated.</x-admin.ui.inline-notice>
                    </x-slot:details>
                    <x-slot:actions>
                        <x-admin.ui.button x-on:click="dismiss()" autofocus>Cancel</x-admin.ui.button>
                        <x-admin.ui.button variant="primary" icon="sparkles" x-on:click="hide(); startGeneration()">Generate suggestions</x-admin.ui.button>
                    </x-slot:actions>
                </x-admin.ui.confirm-dialog>
            </template>

            <template x-if="pendingSaveAll">
                <x-admin.ui.confirm-dialog
                    id="rg-admin-translation-save-all"
                    icon="check-check"
                    :title="$saveAllTitle"
                    x-on:dismiss="pendingSaveAll = false"
                >
                    <p>This publishes these AI suggestions to visitors. Anything whose English source or stored translation changed will be skipped.</p>
                    <x-slot:actions>
                        <x-admin.ui.button x-on:click="dismiss()" autofocus>Cancel</x-admin.ui.button>
                        <x-admin.ui.button variant="primary" x-on:click="hide(); saveAll()">
                            <span x-text="'Save ' + figure(saveAllUnits.length) + (saveAllUnits.length === 1 ? ' translation' : ' translations')">Save translations</span>
                        </x-admin.ui.button>
                    </x-slot:actions>
                </x-admin.ui.confirm-dialog>
            </template>

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
