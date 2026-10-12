@php
    /*
     * Translation AI: read-only technical monitoring of AI-assisted
     * translation. Everything here is TranslationAiDiagnostics' view data —
     * nothing is queried from this view — except Background generations, a
     * component of its own over the temporary store.
     *
     * The header band carries the page's own controls: the model the history
     * is narrowed to (FRM-11), the period (FRM-08) and Refresh. Each is a
     * Livewire round trip that draws the page again, and the URL keeps them.
     */
    $modelCount = max(0, count($modelOptions) - 1);
@endphp

<div class="rg-admin rg-admin-screen rg-admin-translation-ai">
    <section class="rg-admin-page-header rg-admin-translation-ai__header" aria-labelledby="rg-admin-translation-ai-title">
        <div class="rg-admin-page-header__text rg-admin-translation-ai__intro">
            <h1 id="rg-admin-translation-ai-title" class="rg-admin-page-header__title">Translation AI</h1>
            <p class="rg-admin-page-header__description">
                Technical monitoring for AI-assisted translation: provider health, usage, failures and background generation.
                Read-only. Translations are edited in <a class="rg-admin-link" href="{{ \App\Filament\Pages\TranslationCenterPage::getUrl() }}">Translation Center</a>.
            </p>
        </div>

        <div class="rg-admin-translation-ai__controls">
            {{-- Keyed by what it shows, so a new choice or period draws it afresh with its counts. --}}
            <x-admin.ui.combobox
                id="rg-admin-translation-ai-model"
                class="rg-admin-translation-ai__model"
                label="AI model"
                :options="$modelOptions"
                :value="$selectedModel"
                :search-placeholder="'Search '.$modelCount.' '.\Illuminate\Support\Str::plural('model', $modelCount)"
                empty="No model matches."
                wire:key="rg-admin-translation-ai-model-{{ $selectedPeriod }}-{{ md5($selectedModel) }}"
                x-on:choose="$event.preventDefault(); $wire.set('model', $event.detail.value)"
            />

            <div class="rg-admin-translation-ai__refresh">
                <span class="rg-admin-translation-ai__updated">Updated {{ $updatedAt }}</span>
                <div x-data="{ period: $wire.$entangle('period', true) }">
                    <x-admin.ui.segmented label="Period" compact :options="\App\Filament\Support\TranslationAiDiagnostics::PERIODS" :value="$selectedPeriod" x-model="period" />
                </div>
                <x-admin.ui.button
                    icon="refresh-cw"
                    wire:click="refreshDiagnostics"
                    wire:loading.attr="disabled"
                    wire:target="refreshDiagnostics"
                >Refresh</x-admin.ui.button>
            </div>
        </div>
    </section>

    <div
        class="rg-admin-screen__body rg-admin-translation-ai__body"
        wire:loading.class="rg-admin-translation-ai__body--loading"
        wire:loading.attr="aria-busy"
        wire:target="period, model, calls, refreshDiagnostics, goToCallsPage"
    >
        @include('filament.pages.translation-ai.overview')

        @include('filament.pages.translation-ai.calls')

        <livewire:admin.translation-ai-generations wire:key="rg-admin-translation-ai-generations" />

        @include('filament.pages.translation-ai.configuration')
    </div>
</div>
