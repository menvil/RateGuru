@php
    /*
     * What one language is missing (OVL-02, 480 wide for a long list): the
     * application catalog's issues when it breaks the contract, then the
     * missing project content by section.
     *
     * Each item links to the editor that holds its translations today. That
     * is the bridge until Translation Center exists, when these links become
     * Translation Center filters; until then nothing that can be edited now
     * loses its way to an editor.
     */
    $row = $missing['row'];
    $project = $row['project'];
    $translated = $project->required === 0 ? 100 : $project->translated * 100 / $project->required;
    $issueCount = count($row['catalog']->issues);
@endphp

<x-admin.ui.drawer
    id="rg-admin-languages-missing"
    wide
    :title="'Missing in '.$row['label']"
    :subtitle="$row['code'].' · '.$project->percentage().'% project content'"
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
                            <span class="rg-admin-languages__item-label">{{ $item['label'] }}</span>
                            <span class="rg-admin-languages__item-field">{{ $item['field'] }}</span>
                        </span>
                        <x-admin.ui.button size="sm" :href="$item['url']">
                            Edit<span class="rg-admin-sr-only"> {{ $item['label'] }}, {{ $item['field'] }}</span>
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
    </x-slot:footer>
</x-admin.ui.drawer>
