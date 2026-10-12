@php
    /*
     * Recent provider calls: one page read from the server at a time, newest
     * first. Every column is a call's metadata; a call records no text, so
     * there is none to show. The card scrolls sideways under its own header
     * where the column is narrower than the grid; the page never does.
     */
    $columns = '--rg-admin-table-columns: 96px 172px 180px 96px 52px 116px 92px 72px minmax(200px, 1fr); --rg-admin-table-min-width: 1076px';
@endphp

<section class="rg-admin-table rg-admin-translation-ai__calls" aria-labelledby="rg-admin-translation-ai-calls">
    <header class="rg-admin-translation-ai__calls-head">
        <div class="rg-admin-translation-ai__section-text">
            <h2 id="rg-admin-translation-ai-calls" class="rg-admin-card__title">Recent provider calls</h2>
            <p class="rg-admin-card__description">Metadata only. Source texts, prompts, translations and API keys are never recorded, so they are never shown here.</p>
        </div>
        <div x-data="{ calls: $wire.$entangle('calls', true) }">
            <x-admin.ui.segmented label="Show calls" compact :options="$recent['filters']" :value="$recent['filter']" x-model="calls" />
        </div>
    </header>

    @if ($recent['empty'] !== null)
        <x-admin.ui.empty-state :tone="$recent['empty']['tone']" :icon="$recent['empty']['tone'] === 'success' ? 'circle-check' : 'activity'" :title="$recent['empty']['title']">
            {{ $recent['empty']['body'] }}
        </x-admin.ui.empty-state>
    @else
        <div class="rg-admin-table__scroll">
            <div class="rg-admin-table__grid" role="table" aria-label="Recent provider calls" style="{{ $columns }}">
                <div class="rg-admin-table__row rg-admin-table__row--head" role="row">
                    <div class="rg-admin-table__cell" role="columnheader">Time</div>
                    <div class="rg-admin-table__cell" role="columnheader">Usage</div>
                    <div class="rg-admin-table__cell" role="columnheader">Model</div>
                    <div class="rg-admin-table__cell" role="columnheader">Language</div>
                    <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader">Items</div>
                    <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader">Tokens in / out</div>
                    <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader">Cost</div>
                    <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader">Latency</div>
                    <div class="rg-admin-table__cell" role="columnheader">Result</div>
                </div>

                @foreach ($recent['rows'] as $call)
                    <div class="rg-admin-table__row rg-admin-translation-ai__call" role="row" data-result="{{ $call['result']['kind'] }}">
                        <div class="rg-admin-table__cell rg-admin-table__cell--padded rg-admin-translation-ai__stack" role="cell">
                            <span class="rg-admin-translation-ai__time">{{ $call['time'] }}</span>
                            <span class="rg-admin-table__meta">{{ $call['date'] }}</span>
                        </div>
                        <div class="rg-admin-table__cell rg-admin-table__cell--padded" role="cell">
                            <span class="rg-admin-translation-ai__usage">
                                <span class="rg-admin-translation-ai__usage-icon" aria-hidden="true"><x-admin.ui.icon :name="$call['usageIcon']" :size="14" /></span>
                                <span class="rg-admin-translation-ai__stack">
                                    <span class="rg-admin-translation-ai__usage-label">{{ $call['usage'] }}</span>
                                    <span class="rg-admin-table__meta">{{ $call['usageNote'] }}</span>
                                </span>
                            </span>
                        </div>
                        <div class="rg-admin-table__cell rg-admin-table__cell--padded rg-admin-translation-ai__stack" role="cell">
                            <span class="rg-admin-translation-ai__model" title="{{ $call['model'] }}">{{ $call['model'] }}</span>
                            <span class="rg-admin-table__meta">{{ $call['provider'] }}</span>
                        </div>
                        <div class="rg-admin-table__cell rg-admin-table__cell--padded rg-admin-translation-ai__stack" role="cell">
                            <span class="rg-admin-translation-ai__language">
                                @if ($call['flag'] !== '')
                                    <span class="rg-admin-translation-ai__flag" aria-hidden="true">{{ $call['flag'] }}</span>
                                @endif
                                <span class="rg-admin-translation-ai__locale" title="{{ $call['language'] }}">{{ $call['locale'] }}</span>
                            </span>
                            @if ($call['providerLocale'] !== null)
                                <span class="rg-admin-table__meta" title="Sent to the provider as {{ $call['providerLocale'] }}">as {{ $call['providerLocale'] }}</span>
                            @endif
                        </div>
                        <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="cell">{{ $call['items'] }}</div>
                        <div @class(['rg-admin-table__cell rg-admin-table__cell--numeric rg-admin-translation-ai__tokens', 'rg-admin-translation-ai__unknown' => $call['tokensUnknown']]) role="cell">
                            {{ $call['tokens'] }}@if ($call['tokensUnknown'])<span class="rg-admin-sr-only"> (usage not reported)</span>@endif
                        </div>
                        <div @class(['rg-admin-table__cell rg-admin-table__cell--numeric', 'rg-admin-translation-ai__unknown' => $call['costUnknown']]) role="cell" @if ($call['costUnknown']) title="Unknown cost: no rates for this model, or no usage reported" @endif>
                            {{ $call['cost'] }}@if ($call['costUnknown'])<span class="rg-admin-sr-only"> (unknown cost)</span>@endif
                        </div>
                        <div @class([
                            'rg-admin-table__cell rg-admin-table__cell--numeric',
                            'rg-admin-translation-ai__latency--due' => $call['latencyTone'] === 'due',
                            'rg-admin-translation-ai__latency--danger' => $call['latencyTone'] === 'danger',
                        ]) role="cell">{{ $call['latency'] }}</div>
                        <div class="rg-admin-table__cell rg-admin-table__cell--padded rg-admin-translation-ai__stack" role="cell">
                            @if ($call['result']['kind'] === 'success')
                                <span class="rg-admin-translation-ai__success"><x-admin.ui.icon name="check" :size="14" :stroke-width="2.5" />{{ $call['result']['label'] }}</span>
                            @else
                                <x-admin.ui.badge
                                    :tone="$call['result']['kind'] === 'failed' ? 'danger' : 'warning'"
                                    :class="\Illuminate\Support\Arr::toCssClasses(['rg-admin-translation-ai__result', 'rg-admin-translation-ai__result--code' => $call['result']['kind'] === 'failed'])"
                                >{{ $call['result']['label'] }}</x-admin.ui.badge>
                                <span class="rg-admin-table__meta rg-admin-translation-ai__result-detail">{{ $call['result']['detail'] }}</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <x-admin.ui.pagination :paginator="$recent['paginator']" :noun="$recent['noun']" note="newest first" action="goToCallsPage" label="Recent provider calls pages" />
    @endif
</section>
