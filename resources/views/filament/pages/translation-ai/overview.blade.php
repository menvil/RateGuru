{{--
    Provider health, Usage and cost, Failures and issues: three sections of
    one card, side by side where the column allows and stacked where it does
    not. Health is the configuration translations run on now; the other two
    are the history the period and the model filter choose.
--}}
<div class="rg-admin-card rg-admin-card--clip rg-admin-translation-ai__overview">
    <div class="rg-admin-translation-ai__sections">
        <section class="rg-admin-translation-ai__section" aria-labelledby="rg-admin-translation-ai-health">
            <header class="rg-admin-translation-ai__section-head">
                <div class="rg-admin-translation-ai__section-text">
                    <h2 id="rg-admin-translation-ai-health" class="rg-admin-card__title">Provider health</h2>
                    <p class="rg-admin-card__description">{{ $health['subtitle'] }}</p>
                </div>
                <x-admin.ui.badge :tone="$health['status']['tone']" :dot="$health['status']['dot']" data-health="{{ $health['status']['label'] }}">{{ $health['status']['label'] }}</x-admin.ui.badge>
            </header>

            <dl class="rg-admin-detail-list rg-admin-translation-ai__details">
                @foreach ($health['rows'] as $row)
                    <div class="rg-admin-detail-row">
                        <x-admin.ui.icon :name="$row['icon']" class="rg-admin-detail-row__icon" />
                        <dt class="rg-admin-detail-row__label">{{ $row['label'] }}</dt>
                        <dd @class([
                            'rg-admin-detail-row__value',
                            'rg-admin-translation-ai__value--mono' => $row['mono'] ?? false,
                            'rg-admin-translation-ai__value--muted' => $row['muted'] ?? false,
                        ])>
                            @isset($row['badge'])
                                <x-admin.ui.badge :tone="$row['badge']['tone']" :dot="$row['badge']['dot']">{{ $row['badge']['label'] }}</x-admin.ui.badge>
                            @else
                                <span class="rg-admin-translation-ai__value-text" title="{{ $row['value'] }}">{{ $row['value'] }}</span>
                            @endisset
                        </dd>
                    </div>
                @endforeach
            </dl>

            <p class="rg-admin-translation-ai__section-note">{{ $health['reason'] }}</p>
        </section>

        <section class="rg-admin-translation-ai__section" aria-labelledby="rg-admin-translation-ai-usage">
            <header class="rg-admin-translation-ai__section-head">
                <div class="rg-admin-translation-ai__section-text">
                    <h2 id="rg-admin-translation-ai-usage" class="rg-admin-card__title">Usage and cost</h2>
                    <p class="rg-admin-card__description">{{ $usage['subtitle'] }}</p>
                </div>
            </header>

            <dl class="rg-admin-translation-ai__figures">
                @foreach ($usage['figures'] as $figure)
                    <div class="rg-admin-translation-ai__figure">
                        <dt class="rg-admin-stat__label">{{ $figure['label'] }}</dt>
                        <dd @class(['rg-admin-translation-ai__figure-value', 'rg-admin-translation-ai__figure-value--quiet' => ! $usage['has']])>{{ $figure['value'] }}</dd>
                        @if ($figure['note'] !== null)
                            <dd @class(['rg-admin-translation-ai__figure-note', 'rg-admin-translation-ai__figure-note--due' => $figure['due']])>{{ $figure['note'] }}</dd>
                        @endif
                    </div>
                @endforeach
            </dl>

            @if ($usage['chart'] !== null)
                @php($chart = $usage['chart'])
                <figure class="rg-admin-translation-ai__chart" aria-labelledby="rg-admin-translation-ai-chart-title">
                    <figcaption class="rg-admin-translation-ai__chart-head">
                        <span id="rg-admin-translation-ai-chart-title" class="rg-admin-translation-ai__chart-title">{{ $chart['title'] }}</span>
                        <span class="rg-admin-translation-ai__legend">
                            <span class="rg-admin-translation-ai__legend-item"><span class="rg-admin-translation-ai__swatch rg-admin-translation-ai__swatch--succeeded" aria-hidden="true"></span>Succeeded</span>
                            <span class="rg-admin-translation-ai__legend-item"><span class="rg-admin-translation-ai__swatch rg-admin-translation-ai__swatch--failed" aria-hidden="true"></span>Failed</span>
                        </span>
                    </figcaption>
                    <ul @class(['rg-admin-translation-ai__bars', 'rg-admin-translation-ai__bars--dense' => $chart['dense']]) aria-label="{{ $chart['title'] }}, up to {{ $chart['max'] }} at the busiest">
                        @foreach ($chart['bars'] as $bar)
                            <li class="rg-admin-translation-ai__bar" title="{{ $bar['title'] }}">
                                <span class="rg-admin-sr-only">{{ $bar['title'] }}</span>
                                @if ($bar['hasFailed'])
                                    <span class="rg-admin-translation-ai__bar-failed" style="height: {{ $bar['failed'] }}%" aria-hidden="true"></span>
                                @endif
                                <span @class(['rg-admin-translation-ai__bar-succeeded', 'rg-admin-translation-ai__bar-succeeded--none' => ! $bar['hasSucceeded'], 'rg-admin-translation-ai__bar-succeeded--under' => $bar['hasFailed']]) style="height: {{ $bar['succeeded'] }}%" aria-hidden="true"></span>
                            </li>
                        @endforeach
                    </ul>
                    <div class="rg-admin-translation-ai__ticks" aria-hidden="true">
                        @foreach ($chart['bars'] as $bar)
                            @if ($bar['tick'] !== null)
                                <span>{{ $bar['tick'] }}</span>
                            @endif
                        @endforeach
                    </div>
                </figure>
            @else
                <x-admin.ui.empty-state icon="chart-no-axes-column" title="Nothing to chart yet" :heading-level="3" class="rg-admin-translation-ai__chart-empty">
                    {{ $usage['empty'] }}
                </x-admin.ui.empty-state>
            @endif
        </section>

        <section class="rg-admin-translation-ai__section" aria-labelledby="rg-admin-translation-ai-failures">
            <header class="rg-admin-translation-ai__section-head">
                <div class="rg-admin-translation-ai__section-text">
                    <h2 id="rg-admin-translation-ai-failures" class="rg-admin-card__title">Failures and issues</h2>
                    <p class="rg-admin-card__description">{{ $failures['subtitle'] }}</p>
                </div>
                @if ($failures['badge'] !== null)
                    <x-admin.ui.badge :tone="$failures['badge']['tone']">{{ $failures['badge']['label'] }}</x-admin.ui.badge>
                @endif
            </header>

            <x-admin.ui.inline-notice :tone="$failures['notice']['tone']" strip role="status">{{ $failures['notice']['text'] }}</x-admin.ui.inline-notice>

            <ul class="rg-admin-translation-ai__failures" aria-label="Failures by error type">
                @foreach ($failures['rows'] as $failure)
                    <li @class(['rg-admin-translation-ai__failure', 'rg-admin-translation-ai__failure--none' => ! $failure['happened']]) data-code="{{ $failure['code'] }}">
                        <div class="rg-admin-translation-ai__failure-text">
                            <span class="rg-admin-translation-ai__failure-code" title="{{ $failure['description'] }}">{{ $failure['code'] }}</span>
                            @if ($failure['happened'])
                                <span class="rg-admin-translation-ai__failure-description">{{ $failure['description'] }}</span>
                                @if ($failure['last'] !== null)
                                    <span class="rg-admin-translation-ai__failure-last">{{ $failure['last'] }} UTC</span>
                                @endif
                            @endif
                        </div>
                        <div class="rg-admin-translation-ai__failure-figures">
                            @if (! $failure['happened'])
                                <span class="rg-admin-translation-ai__failure-count rg-admin-translation-ai__failure-count--none">0</span>
                            @endif
                            @if ($failure['calls'] !== null)
                                <span class="rg-admin-translation-ai__failure-count">{{ $failure['calls'] }}</span>
                            @endif
                            @if ($failure['items'] !== null)
                                <span class="rg-admin-translation-ai__failure-items">{{ $failure['items'] }}</span>
                            @endif
                            @if ($failure['pattern'] !== null)
                                <x-admin.ui.badge :tone="$failure['pattern']['tone']" title="{{ $failure['pattern']['hint'] }}">{{ $failure['pattern']['label'] }}</x-admin.ui.badge>
                            @elseif ($failure['itemsOnly'])
                                <span class="rg-admin-translation-ai__failure-note">Provider answered</span>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>

            <p class="rg-admin-translation-ai__section-note">
                Burst: five or more failed calls of one type within 10 min. Ongoing: such a series in the last 15 min.
                Failed items are translations a provider returned that did not pass their checks; on their own they say nothing about the provider being down.
            </p>
        </section>
    </div>
</div>
