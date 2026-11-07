@php
    /*
     * What one language is missing (OVL-02, 480 wide for a long list): the
     * application catalog's issues when it breaks the contract, then the
     * missing project content by section.
     *
     * Each item shows the English text it is translated from. Translate opens
     * Translation Center on that item — its language, its section, Missing
     * only — and Translate all missing on everything this language is missing.
     * Edit source opens the editor that holds the content's English text.
     * Catalog issues are the release's to fix, so nothing here sends them to
     * Translation Center, which edits project content only.
     */
    $row = $missing['row'];
    $project = $row['project'];
    $translated = $project->required === 0 ? 100 : $project->translated * 100 / $project->required;
    $issueCount = count($row['catalog']->issues);
    $subtitle = number_format($row['missing']).' of '.number_format($project->required).' project strings missing · '.($row['enabled'] ? 'enabled' : 'disabled');
@endphp

<x-admin.ui.drawer
    id="rg-admin-languages-missing"
    wide
    :title="'Missing in '.$row['label'].' — '.$row['native']"
    :subtitle="$subtitle"
    wire:key="missing-{{ $row['code'] }}"
    x-on:dismiss="$wire.closeMissing()"
>
    <x-slot:leading>
        <span class="rg-admin-languages__drawer-flag">{{ $row['flag'] }}</span>
    </x-slot:leading>

    <div class="rg-admin-languages__summary">
        <div class="rg-admin-progress rg-admin-progress--summary" aria-hidden="true">
            <span @class(['rg-admin-progress__bar', 'rg-admin-progress__bar--complete' => $row['missing'] === 0]) style="width: {{ $translated }}%"></span>
            <span class="rg-admin-progress__bar rg-admin-progress__bar--missing" style="width: {{ 100 - $translated }}%"></span>
        </div>
        <ul class="rg-admin-progress-legend">
            <li class="rg-admin-progress-legend__item">
                <span class="rg-admin-progress-legend__swatch" aria-hidden="true"></span>
                Translated {{ number_format($project->translated) }}
            </li>
            <li class="rg-admin-progress-legend__item">
                <span class="rg-admin-progress-legend__swatch rg-admin-progress-legend__swatch--missing" aria-hidden="true"></span>
                Missing {{ number_format($row['missing']) }}
            </li>
        </ul>
    </div>

    @if ($missing['issues'] !== [])
        <section class="rg-admin-languages__drawer-section" aria-labelledby="rg-admin-languages-missing-application">
            <div class="rg-admin-section-label">
                <h3 id="rg-admin-languages-missing-application" class="rg-admin-section-label__text">Application translations</h3>
                <span class="rg-admin-section-label__trailing">{{ number_format($issueCount) }} {{ \Illuminate\Support\Str::plural('issue', $issueCount) }}</span>
            </div>
            <x-admin.ui.inline-notice tone="warning">
                The release breaks the catalog contract for this language, so it cannot be enabled.
            </x-admin.ui.inline-notice>
            <ul class="rg-admin-languages__issues">
                @foreach ($missing['issues'] as $issue)
                    <li>{{ $issue }}</li>
                @endforeach
                @if ($missing['unlisted'] > 0)
                    <li class="rg-admin-languages__issues-more">… and {{ number_format($missing['unlisted']) }} more</li>
                @endif
            </ul>
        </section>
    @endif

    @forelse ($missing['sections'] as $section)
        <section class="rg-admin-languages__drawer-section" aria-labelledby="rg-admin-languages-missing-section-{{ $loop->index }}">
            <div class="rg-admin-section-label">
                <h3 id="rg-admin-languages-missing-section-{{ $loop->index }}" class="rg-admin-section-label__text">{{ $section['label'] }}</h3>
                <span class="rg-admin-section-label__trailing">{{ number_format(count($section['items'])) }} missing</span>
            </div>
            <ul class="rg-admin-languages__items">
                @foreach ($section['items'] as $item)
                    <li class="rg-admin-languages__item">
                        <span class="rg-admin-languages__item-text">
                            <span class="rg-admin-languages__item-name">
                                {{ $item['label'] }}@if ($item['field'] !== null)<span class="rg-admin-languages__item-field"> · {{ $item['field'] }}</span>@endif
                            </span>
                            <span class="rg-admin-languages__item-reference" title="{{ $item['reference'] }}">
                                <span lang="{{ $referenceCode }}">{{ strtoupper($referenceCode) }}</span> “{{ $item['reference'] }}”
                            </span>
                        </span>
                        <a href="{{ $item['url'] }}" class="rg-admin-languages__edit-source">
                            Edit source<span class="rg-admin-sr-only">: {{ $item['label'] }}{{ $item['field'] !== null ? ', '.$item['field'] : '' }}</span>
                        </a>
                        <x-admin.ui.button size="sm" :href="$item['translate']">
                            Translate<span class="rg-admin-sr-only"> {{ $item['label'] }}{{ $item['field'] !== null ? ', '.$item['field'] : '' }}</span>
                        </x-admin.ui.button>
                    </li>
                @endforeach
            </ul>
        </section>
    @empty
        <x-admin.ui.empty-state icon="circle-check" tone="success" title="Project content is complete">
            Every piece of project content has a translation.
        </x-admin.ui.empty-state>
    @endforelse

    <x-slot:footer>
        <span class="rg-admin-languages__drawer-note">Visitors see the {{ $referenceLabel }} text wherever a translation is missing.</span>
        @if ($missing['translateAll'] !== null)
            <x-admin.ui.button variant="primary" icon="languages" :href="$missing['translateAll']">
                Translate all missing
            </x-admin.ui.button>
        @endif
    </x-slot:footer>
</x-admin.ui.drawer>
