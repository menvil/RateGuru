@php
    /*
     * Analytics → Experiments (ExperimentsTab), as the reference draws it:
     * the latest comparisons to choose from, with the way to all of them in
     * Experiments and the word that the page's filters don't apply; the
     * chosen one's header card — its versions, game and audience, status and
     * facts, then a panel per version with the steps its visitors meet — what
     * the figures say so far, and the table of every metric against the
     * reference version, which scrolls sideways inside its card with the
     * metric column held in place. No comparison yet, or nobody sent to a
     * version yet, is said in place of what can't be drawn.
     */
@endphp

<div class="rg-admin-experiments__chooser" data-testid="analytics-experiments-chooser">
    @if (! $data['empty'])
        <div class="rg-admin-experiments__choices" role="group" aria-label="Comparisons">
            @foreach ($data['choices'] as $choice)
                <button
                    type="button"
                    class="rg-admin-experiments__choice"
                    aria-pressed="{{ $choice['chosen'] ? 'true' : 'false' }}"
                    wire:click="$set('exp', '{{ $choice['id'] }}')"
                    wire:key="analytics-experiment-{{ $choice['id'] }}"
                    data-period="{{ $choice['id'] }}"
                >
                    <x-admin.ui.icon :name="$choice['icon']" :size="14" class="rg-admin-experiments__choice-icon" />
                    {{-- Cut short where a wide screen's chip is narrow, the whole name on hover; wrapped whole on a phone. --}}
                    <span class="rg-admin-experiments__choice-name" title="{{ $choice['name'] }}">{{ $choice['name'] }}</span>
                    <span class="rg-admin-experiments__choice-meta">{{ $choice['meta'] }}</span>
                </button>
            @endforeach
            <a href="{{ $data['allUrl'] }}" class="rg-admin-experiments__all">All in Experiments<x-admin.ui.icon name="arrow-right" :size="14" /></a>
        </div>
    @endif
    <span class="rg-admin-experiments__caption">{{ $data['caption'] }}</span>
</div>

@if ($data['empty'])
    <x-admin.ui.card>
        <x-admin.ui.empty-state icon="flask-conical" title="No comparisons yet" data-testid="analytics-experiments-empty">
            A traffic period that gives two or more versions a share compares them here. Shares change in each game’s Traffic tab.
            <x-slot:action>
                <x-admin.ui.button size="sm" trailing-icon="arrow-right" :href="$data['allUrl']">All in Experiments</x-admin.ui.button>
            </x-slot:action>
        </x-admin.ui.empty-state>
    </x-admin.ui.card>
@else
    <x-admin.ui.card flush clip data-testid="analytics-experiment" wire:key="analytics-experiment-card-{{ $data['periodId'] }}">
        <div class="rg-admin-experiments__summary">
            <div class="rg-admin-experiments__heading">
                <div class="rg-admin-experiments__title-row">
                    <h2 class="rg-admin-experiments__name">{{ $data['title'] }}</h2>
                    <x-admin.ui.badge :tone="$data['status']['tone']" :dot="$data['status']['dot']">{{ $data['status']['label'] }}</x-admin.ui.badge>
                </div>
                <p class="rg-admin-experiments__question">{{ $data['subtitle'] }}</p>
            </div>
            <dl class="rg-admin-experiments__facts">
                @foreach ($data['facts'] as $fact)
                    <div class="rg-admin-experiments__fact">
                        <dt class="rg-admin-experiments__fact-label">{{ $fact['label'] }}</dt>
                        <dd class="rg-admin-experiments__fact-value">{{ $fact['value'] }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
        <div class="rg-admin-experiments__variants">
            @foreach ($data['variants'] as $variant)
                <div class="rg-admin-experiments__variant" data-testid="analytics-experiment-variant">
                    <div class="rg-admin-experiments__variant-head">
                        <span class="rg-admin-experiments__variant-key">{{ $variant['key'] }}</span>
                        <span class="rg-admin-experiments__variant-name">{{ $variant['name'] }}</span>
                        @if ($variant['reference'])
                            <x-admin.ui.badge tone="outline">Reference</x-admin.ui.badge>
                        @endif
                    </div>
                    <span class="rg-admin-experiments__variant-meta">{{ $variant['meta'] }}</span>
                    @if ($variant['steps'] !== [])
                        <ol class="rg-admin-experiments__steps" aria-label="Steps of {{ $variant['key'] }}">
                            @foreach ($variant['steps'] as $step)
                                <li class="rg-admin-experiments__step">
                                    <span @class(['rg-admin-experiments__step-chip', 'rg-admin-experiments__step-chip--'.$step['kind']])>{{ $step['label'] }}</span>
                                    @unless ($loop->last)
                                        <x-admin.ui.icon name="chevron-right" :size="12" class="rg-admin-experiments__step-arrow" />
                                    @endunless
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </div>
            @endforeach
        </div>
    </x-admin.ui.card>

    @if ($data['verdict'] !== null)
        <div @class(['rg-admin-experiments__verdict', 'rg-admin-experiments__verdict--'.$data['verdict']['tone']]) data-testid="analytics-experiment-verdict" data-kind="{{ $data['verdict']['kind'] }}">
            <x-admin.ui.icon :name="$data['verdict']['icon']" :size="16" class="rg-admin-experiments__verdict-icon" />
            <p class="rg-admin-experiments__verdict-text"><strong class="rg-admin-experiments__verdict-title">{{ $data['verdict']['title'] }}</strong> {{ $data['verdict']['text'] }}</p>
        </div>
    @endif

    @if ($data['fresh'] !== null)
        <x-admin.ui.card>
            <x-admin.ui.empty-state icon="hourglass" :title="$data['fresh']['title']" data-testid="analytics-experiment-fresh">{{ $data['fresh']['text'] }}</x-admin.ui.empty-state>
        </x-admin.ui.card>
    @elseif ($data['table'] !== null)
        @php($table = $data['table'])
        <x-admin.ui.card flush clip data-testid="analytics-experiment-table">
            <div class="rg-admin-table__scroll">
                <div class="rg-admin-table__grid rg-admin-experiments__compare" role="table" aria-label="Versions compared" style="{{ $table['columns'] }}">
                    <div class="rg-admin-table__row rg-admin-table__row--head rg-admin-experiments__compare-row" role="row">
                        <div class="rg-admin-table__cell rg-admin-experiments__pinned" role="columnheader">Metric · share of assigned</div>
                        <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader">{{ $table['heads']['reference'] }}</div>
                        @foreach ($table['heads']['groups'] as $head)
                            <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader">{{ $head['version'] }}</div>
                            <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader">{{ $head['change'] }}</div>
                            <div class="rg-admin-table__cell" role="columnheader">Evidence</div>
                        @endforeach
                    </div>

                    @foreach ($table['rows'] as $row)
                        <div @class(['rg-admin-table__row rg-admin-experiments__compare-row', 'rg-admin-experiments__compare-row--primary' => $row['primary']]) role="row" data-testid="analytics-experiment-row">
                            <div class="rg-admin-table__cell rg-admin-experiments__pinned rg-admin-experiments__metric-name" role="rowheader">
                                <span class="rg-admin-experiments__metric-label">
                                    {{ $row['label'] }}
                                    @if ($row['primary'])
                                        <x-admin.ui.badge tone="outline">Primary</x-admin.ui.badge>
                                    @endif
                                </span>
                                @if ($row['sub'] !== '')
                                    <span class="rg-admin-experiments__metric-sub">{{ $row['sub'] }}</span>
                                @endif
                            </div>
                            <div class="rg-admin-table__cell rg-admin-table__cell--numeric rg-admin-experiments__figures" role="cell">
                                <span @class(['rg-admin-experiments__figure-rate', 'rg-admin-experiments__figure-rate--none' => $row['reference']['none']])>{{ $row['reference']['rate'] }}</span>
                                @if ($row['reference']['count'] !== '')
                                    <span class="rg-admin-experiments__figure-count">{{ $row['reference']['count'] }}</span>
                                @endif
                            </div>
                            @foreach ($row['groups'] as $group)
                                <div class="rg-admin-table__cell rg-admin-table__cell--numeric rg-admin-experiments__figures" role="cell">
                                    <span @class(['rg-admin-experiments__figure-rate', 'rg-admin-experiments__figure-rate--none' => $group['none']])>{{ $group['rate'] }}</span>
                                    @if ($group['count'] !== '')
                                        <span class="rg-admin-experiments__figure-count">{{ $group['count'] }}</span>
                                    @endif
                                </div>
                                <div class="rg-admin-table__cell rg-admin-table__cell--numeric rg-admin-experiments__change" role="cell">{{ $group['change'] }}</div>
                                <div class="rg-admin-table__cell rg-admin-experiments__evidence" role="cell">
                                    @if ($group['evidence'] !== null)
                                        <x-admin.ui.badge :tone="$group['evidence']['tone']">{{ $group['evidence']['label'] }}</x-admin.ui.badge>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endforeach

                    @if ($table['conditional'] !== [])
                        <div class="rg-admin-experiments__section" role="row">
                            <span class="rg-admin-experiments__pinned rg-admin-experiments__section-label" role="rowheader">Conditional rates · secondary</span>
                        </div>
                    @endif

                    @foreach ($table['conditional'] as $row)
                        <div class="rg-admin-table__row rg-admin-experiments__compare-row rg-admin-experiments__compare-row--secondary" role="row" data-testid="analytics-experiment-conditional">
                            <div class="rg-admin-table__cell rg-admin-experiments__pinned rg-admin-experiments__metric-name" role="rowheader">
                                <span class="rg-admin-experiments__metric-label rg-admin-experiments__metric-label--secondary">{{ $row['label'] }}</span>
                                @if ($row['note'] !== '')
                                    <span class="rg-admin-experiments__metric-sub">{{ $row['note'] }}</span>
                                @endif
                            </div>
                            <div class="rg-admin-table__cell rg-admin-table__cell--numeric rg-admin-experiments__figure-rate" role="cell">{{ $row['reference'] }}</div>
                            @foreach ($row['groups'] as $group)
                                <div class="rg-admin-table__cell rg-admin-table__cell--numeric rg-admin-experiments__figure-rate" role="cell">{{ $group['rate'] }}</div>
                                <div class="rg-admin-table__cell rg-admin-table__cell--numeric rg-admin-experiments__change rg-admin-experiments__change--secondary" role="cell">{{ $group['change'] }}</div>
                                <div class="rg-admin-table__cell" role="cell"></div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
            <p class="rg-admin-experiments__foot rg-admin-experiments__compare-foot">{{ $table['foot'] }}</p>
        </x-admin.ui.card>
    @endif
@endif
