@php
    /*
     * Analytics → Site engagement (EngagementTab), as the reference draws
     * it: what the tab counts on a band of its own, the nine tiles — each
     * with the pill of what it counts, people, actions or accounts — the
     * website funnel beside two sources compared, then the posts opened
     * most. While Record site visits is off the tab says so, with the way to
     * Tracking, and draws the accounts alone; nobody matching the filters is
     * said once, with nothing else drawn.
     */
    use App\Filament\Pages\Analytics\EngagementTab;
@endphp

@if ($data['notice'] !== null)
    <x-admin.ui.inline-notice tone="info" data-testid="analytics-engagement-site-off">
        {{ $data['notice']['text'] }}
        <x-slot:actions><x-admin.ui.button size="sm" trailing-icon="arrow-right" :href="$data['notice']['url']">Open Tracking</x-admin.ui.button></x-slot:actions>
    </x-admin.ui.inline-notice>
@endif

@if ($data['empty'])
    @include('filament.pages.analytics.elements.no-visitors', ['range' => $data['range'], 'filtered' => $data['filtered']])
@else
    @isset($data['scope'])
        <x-admin.ui.inline-notice tone="info" icon="globe" data-testid="analytics-engagement-scope">{{ $data['scope'] }}</x-admin.ui.inline-notice>
    @endisset

    <x-admin.ui.card flush clip data-testid="analytics-engagement-cards">
        <dl @class(['rg-admin-analytics__kpis', 'rg-admin-analytics__kpis--engagement' => ! $data['siteOff']])>
            @foreach ($data['cards'] as $card)
                <div class="rg-admin-analytics__kpi" data-testid="analytics-engagement-card">
                    <dt class="rg-admin-analytics__kpi-label">
                        {{ $card['label'] }}
                        <span class="rg-admin-analytics__shield" title="Counted by {{ site_name() }}"><x-admin.ui.icon name="shield-check" :size="12" /></span>
                    </dt>
                    <dd class="rg-admin-analytics__kpi-value">{{ $card['value'] }}</dd>
                    <dd class="rg-admin-analytics__kpi-pill"><span @class(['rg-admin-analytics__unit', 'rg-admin-analytics__unit--actions' => $card['tone'] === 'actions'])>{{ $card['unit'] }}</span></dd>
                    @if ($card['note'] !== '')
                        <dd class="rg-admin-analytics__kpi-change">{{ $card['note'] }}</dd>
                    @endif
                    @if ($card['flag'] !== null)
                        <dd class="rg-admin-analytics__kpi-flag" data-testid="analytics-too-early">
                            <x-admin.ui.badge tone="warning" :title="$card['flag']['hint']">{{ $card['flag']['label'] }}</x-admin.ui.badge>
                            <span class="rg-admin-sr-only">{{ $card['flag']['hint'] }}</span>
                        </dd>
                    @endif
                </div>
            @endforeach
        </dl>
    </x-admin.ui.card>

    @unless ($data['siteOff'])
        <div class="rg-admin-analytics__pair">
            <x-admin.ui.card title="Website funnel" :description="$data['funnel']['note']" data-testid="analytics-engagement-funnel">
                <ol class="rg-admin-analytics__funnel">
                    @foreach ($data['funnel']['steps'] as $step)
                        <li class="rg-admin-analytics__step" title="{{ $step['hint'] }}">
                            <span class="rg-admin-analytics__step-name">
                                <span class="rg-admin-analytics__step-label">{{ $step['name'] }}</span>
                                <span class="rg-admin-analytics__track" aria-hidden="true"><span @class(['rg-admin-analytics__fill', 'rg-admin-analytics__fill--strong' => $step['strong']]) style="width: {{ $step['bar'] }}%"></span></span>
                            </span>
                            <span class="rg-admin-analytics__step-count">{{ $step['count'] }}</span>
                            <span class="rg-admin-analytics__step-rate">{{ $step['previous'] }}</span>
                            <span class="rg-admin-analytics__step-first">{{ $step['first'] }}</span>
                        </li>
                    @endforeach
                </ol>
                <div class="rg-admin-analytics__step rg-admin-analytics__step--head rg-admin-analytics__step--legend" aria-hidden="true">
                    <span>Unique visitors at each step</span>
                    <span>Count</span>
                    <span>Of previous</span>
                    <span>Of visitors</span>
                </div>
            </x-admin.ui.card>

            <x-admin.ui.card title="Compare sources" :description="$data['compare']['note'] ?? 'Same period and device, whatever source is chosen.'" flush data-testid="analytics-engagement-compare">
                @if ($data['compare'] === null)
                    <p class="rg-admin-analytics__versus-none">No source is recorded for these visits, so there is nothing to compare.</p>
                @else
                    <div class="rg-admin-analytics__versus-pickers">
                        @foreach ($data['compare']['pickers'] as $picker)
                            <div class="rg-admin-analytics__versus-picker">
                                <span @class(['rg-admin-analytics__versus-tag', 'rg-admin-analytics__versus-tag--b' => $picker['key'] === 'B']) aria-hidden="true">{{ $picker['key'] }}</span>
                                {{-- One radio group a side: one tab stop, and the arrows, Home and End choose, as every radio group here. --}}
                                <div
                                    class="rg-admin-analytics__versus-options"
                                    role="radiogroup"
                                    aria-label="Source {{ $picker['key'] }}"
                                    x-data="{
                                        move(to) {
                                            const radios = [...$el.querySelectorAll('[role=radio]')]
                                            const at = Math.max(0, radios.findIndex((radio) => radio.getAttribute('aria-checked') === 'true'))
                                            const next = radios[to === 'first' ? 0 : (to === 'last' ? radios.length - 1 : (at + to + radios.length) % radios.length)]

                                            if (next.getAttribute('aria-checked') === 'true') return

                                            // The focus follows the choice once it is drawn — unless it went elsewhere meanwhile.
                                            $wire.$set(@js($picker['property']), next.dataset.value).then(() => {
                                                if ($el.contains(document.activeElement) || document.activeElement === document.body) {
                                                    $el.querySelector('[role=radio][aria-checked=true]')?.focus()
                                                }
                                            }, () => {})
                                        },
                                    }"
                                    x-on:keydown.arrow-right.prevent="move(1)"
                                    x-on:keydown.arrow-down.prevent="move(1)"
                                    x-on:keydown.arrow-left.prevent="move(-1)"
                                    x-on:keydown.arrow-up.prevent="move(-1)"
                                    x-on:keydown.home.prevent="move('first')"
                                    x-on:keydown.end.prevent="move('last')"
                                >
                                    @foreach ($picker['options'] as $option)
                                        <button
                                            type="button"
                                            role="radio"
                                            data-value="{{ $option['key'] }}"
                                            tabindex="{{ $option['on'] ? '0' : '-1' }}"
                                            aria-checked="{{ $option['on'] ? 'true' : 'false' }}"
                                            title="{{ $option['title'] }}"
                                            @class(['rg-admin-analytics__versus-option', 'rg-admin-analytics__versus-option--on' => $option['on']])
                                            wire:click="$set('{{ $picker['property'] }}', @js($option['key']))"
                                        >{{ $option['label'] }}</button>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="rg-admin-table__grid rg-admin-analytics__versus" role="table" aria-label="Compare sources">
                        <div class="rg-admin-table__row rg-admin-table__row--head" role="row">
                            <div class="rg-admin-table__cell" role="columnheader">Metric</div>
                            <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader">A · {{ $data['compare']['names']['a'] }}</div>
                            <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader">B · {{ $data['compare']['names']['b'] }}</div>
                        </div>
                        @foreach ($data['compare']['rows'] as $row)
                            <div class="rg-admin-table__row rg-admin-analytics__versus-row" role="row" data-testid="analytics-engagement-compare-row">
                                <div class="rg-admin-table__cell rg-admin-analytics__versus-label" role="cell">{{ $row['label'] }}</div>
                                <div @class(['rg-admin-table__cell rg-admin-table__cell--numeric rg-admin-analytics__versus-figure', 'rg-admin-analytics__versus-figure--lead' => $row['lead'] === 'a']) role="cell">{{ $row['a'] }}</div>
                                <div @class(['rg-admin-table__cell rg-admin-table__cell--numeric rg-admin-analytics__versus-figure', 'rg-admin-analytics__versus-figure--lead' => $row['lead'] === 'b']) role="cell">{{ $row['b'] }}</div>
                            </div>
                        @endforeach
                    </div>
                    <p class="rg-admin-analytics__versus-answer" data-testid="analytics-engagement-answer">{{ $data['compare']['answer'] }}</p>
                @endif
            </x-admin.ui.card>
        </div>

        <x-admin.ui.card title="Content engagement" :description="$data['content']['note']" flush data-testid="analytics-engagement-content">
            @if (! $data['content']['kept'])
                <x-admin.ui.empty-state icon="history" :title="EngagementTab::POSTS_KEPT">
                    Pick a period within the last 90 days to see the posts people opened.
                </x-admin.ui.empty-state>
            @elseif ($data['content']['rows'] === [])
                <x-admin.ui.empty-state icon="newspaper" title="No post opened">
                    Nobody opened a post in these days.
                </x-admin.ui.empty-state>
            @else
                <div class="rg-admin-table__scroll">
                    <div class="rg-admin-table__grid rg-admin-analytics__posts" role="table" aria-label="Content engagement">
                        <div class="rg-admin-table__row rg-admin-table__row--head" role="row">
                            <div class="rg-admin-table__cell" role="columnheader">Post</div>
                            @foreach (['Views', 'Unique viewers', 'Votes', 'Comments', 'Engagement', 'Registrations'] as $column)
                                <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader">{{ $column }}</div>
                            @endforeach
                        </div>
                        @foreach ($data['content']['rows'] as $row)
                            <div class="rg-admin-table__row rg-admin-analytics__post-row" role="row" data-testid="analytics-engagement-post" wire:key="analytics-engagement-post-{{ $row['id'] }}">
                                <div class="rg-admin-table__cell rg-admin-analytics__post" role="cell">
                                    <span @class(['rg-admin-analytics__post-title', 'rg-admin-analytics__post-title--removed' => $row['removed']]) title="{{ $row['title'] }}">{{ $row['title'] }}</span>
                                    @if ($row['url'] !== null)
                                        <a href="{{ $row['url'] }}" target="_blank" rel="noopener" class="rg-admin-analytics__post-id rg-admin-analytics__post-id--link">#{{ $row['id'] }}</a>
                                    @else
                                        <span class="rg-admin-analytics__post-id">#{{ $row['id'] }}</span>
                                    @endif
                                </div>
                                <div class="rg-admin-table__cell rg-admin-table__cell--numeric rg-admin-analytics__post-figure" role="cell">{{ $row['views'] }}</div>
                                <div class="rg-admin-table__cell rg-admin-table__cell--numeric rg-admin-analytics__post-figure" role="cell">{{ $row['viewers'] }}</div>
                                <div class="rg-admin-table__cell rg-admin-table__cell--numeric rg-admin-analytics__post-figure" role="cell">
                                    <span>{{ $row['votes'] }}</span>
                                    <span class="rg-admin-analytics__post-sub">{{ $row['voters'] }}</span>
                                </div>
                                <div class="rg-admin-table__cell rg-admin-table__cell--numeric rg-admin-analytics__post-figure" role="cell">{{ $row['comments'] }}</div>
                                <div class="rg-admin-table__cell rg-admin-table__cell--numeric rg-admin-analytics__post-figure rg-admin-analytics__post-figure--strong" role="cell" @if ($row['engagementTitle'] !== null) title="{{ $row['engagementTitle'] }}" @endif>{{ $row['engagement'] }}</div>
                                <div @class(['rg-admin-table__cell rg-admin-table__cell--numeric rg-admin-analytics__post-figure', 'rg-admin-analytics__post-figure--quiet' => $row['quiet']]) role="cell">{{ $row['registrations'] }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>
                <x-slot:footer data-testid="analytics-engagement-foot">{{ $data['content']['foot'] }}</x-slot:footer>
            @endif
        </x-admin.ui.card>
    @endunless
@endif
