{{--
    A source's editor, as the reference draws it: one draft in four panes —
    Link & parameters (the preset it started from and what it filled in,
    the API connection, name, key, the link's parameters, the window and the
    network's day), Spend, Postbacks and Blocking — the block rule's general
    thresholds or the source's own, what a saved source has blocked, where
    those blocks stand and how blocking works — saved together with its
    state; its API connected or disconnected at once, the key never shown
    again. A pane holding a refusal is marked.
--}}
@php
    $s = $draft['source'];
    $network = ($s['type'] ?? 'network') === 'network';
    $field = fn (string $key): ?string => $errors->first($key) ?: null;
    $failing = fn (array $keys): bool => collect($errors->keys())->contains(fn (string $key): bool => collect($keys)->contains(fn (string $prefix): bool => str_starts_with($key, $prefix)));
    $panes = array_filter([
        'link' => ['Link & parameters', $failing(['name', 'link_key', 'link_params', 'attribution_days', 'reporting_timezone'])],
        'spend' => ['Spend', $failing(['spend_mode', 'rate_', 'fixed_', 'target_cpa', 'cost_unit'])],
        'postbacks' => $network ? ['Postbacks'.(count($draft['rules']) > 0 ? ' '.count($draft['rules']) : ''), $failing(['rules'])] : null,
        'blocking' => $network ? ['Blocking', $failing(['block_'])] : null,
    ]);
    $connected = $editor['connection'] !== null && $editor['connection']['status']->value !== 'disconnected';
    $presetName = $editor['preset']['name'] ?? null;
@endphp
<x-admin.ui.drawer id="rg-admin-traffic-source" editor :title="$editor['new'] ? 'New source' : ($s['name'] ?? 'Source')" :subtitle="implode(' · ', array_filter([$network ? 'Ad network' : 'Banner or link', $presetName !== null ? $presetName.' preset' : null, ucfirst((string) ($s['status'] ?? 'active'))]))" wire:key="source-editor-{{ $edit }}" x-on:dismiss="$wire.closeEditor()">
    <div class="rg-admin-traffic__drawer" x-data="{ pane: 'link' }">
        <div class="rg-admin-traffic__panes" role="tablist" aria-label="Source">
            @foreach ($panes as $key => [$label, $hasError])
                <button type="button" role="tab" class="rg-admin-traffic__pane-tab" x-bind:aria-selected="pane === '{{ $key }}' ? 'true' : 'false'" x-bind:class="{ 'rg-admin-traffic__pane-tab--on': pane === '{{ $key }}' }" x-on:click="pane = '{{ $key }}'">
                    {{ $label }}@if ($hasError)<span class="rg-admin-traffic__pane-error" aria-label="Has a refusal"></span>@endif
                </button>
            @endforeach
        </div>

        <div class="rg-admin-traffic__pane" x-show="pane === 'link'">
            @if ($presetName !== null)
                <div class="rg-admin-traffic__preset-card">
                    <strong>Started from the {{ $presetName }} preset</strong>
                    <span class="rg-admin-traffic__note">Its tokens and postback address are filled in below and stay editable. A later change to the preset doesn’t touch this source.</span>
                    <div class="rg-admin-traffic__preset-chips">
                        <span class="rg-admin-traffic__preset-chip"><x-admin.ui.icon name="link" :size="12" /><span><strong>Link</strong><span class="rg-admin-traffic__note">Tokens filled in</span></span></span>
                        <span class="rg-admin-traffic__preset-chip"><x-admin.ui.icon name="arrow-up-right" :size="12" /><span><strong>Postbacks</strong><span class="rg-admin-traffic__note">{{ $draft['rules'] === [] ? 'None yet' : 'Address filled in' }}</span></span></span>
                        <span @class(['rg-admin-traffic__preset-chip', 'rg-admin-traffic__preset-chip--on' => ($s['spend_mode'] ?? '') === 'api']) ><x-admin.ui.icon name="refresh-cw" :size="12" /><span><strong>Spend</strong><span class="rg-admin-traffic__note">{{ match ($s['spend_mode'] ?? '') { 'api' => 'Synced from the API', 'click_cost' => 'From each click', 'rate' => 'A rate you set', 'manual' => 'Typed in or a file', default => 'Not counted' } }}</span></span></span>
                        <span @class(['rg-admin-traffic__preset-chip', 'rg-admin-traffic__preset-chip--on' => $connected]) ><x-admin.ui.icon name="ban" :size="12" /><span><strong>Blocking</strong><span class="rg-admin-traffic__note">{{ $connected ? 'Through the API' : 'By hand' }}</span></span></span>
                    </div>
                    @if (($editor['preset']['fill'] ?? '') !== '')
                        <span class="rg-admin-traffic__preset-fill"><x-admin.ui.icon name="pencil" :size="12" /><span><strong>You fill in:</strong> {{ $editor['preset']['fill'] }}</span></span>
                    @endif
                    @if (($editor['preset']['window_note'] ?? '') !== '')
                        <span class="rg-admin-traffic__note">{{ $editor['preset']['window_note'] }}</span>
                    @endif
                    @if ($connected)
                        <div class="rg-admin-traffic__connection">
                            <span class="rg-admin-traffic__status"><span class="rg-admin-traffic__status-dot rg-admin-traffic__status-dot--{{ $editor['connection']['status']->value === 'connected' ? 'success' : 'danger' }}"></span>{{ $editor['connection']['status']->value === 'connected' ? 'Connected' : 'Needs attention' }}{{ $editor['connection']['account_label'] ? ' as '.$editor['connection']['account_label'] : '' }}</span>
                            <x-admin.ui.button size="sm" wire:click="disconnectApi">Disconnect</x-admin.ui.button>
                        </div>
                        <span class="rg-admin-traffic__note">{{ $editor['connection']['last_synced_at'] ? 'Last sync '.$editor['connection']['last_synced_at']->format('j M H:i').'. ' : '' }}{{ $editor['connector']['promise'] ?? '' }}{{ $editor['connection']['last_error'] ? ' · '.\App\Enums\TrafficConnectorFailure::tryFrom($editor['connection']['last_error'])?->message() : '' }}</span>
                    @endif
                </div>
            @endif

            <div class="rg-admin-traffic__name-key">
                <x-admin.ui.text-field label="Name" id="rg-admin-source-name" :value="$s['name'] ?? ''" :error="$field('name')" wire:model="draft.source.name" />
                <x-admin.ui.text-field label="Key in links" id="rg-admin-source-key" :value="$s['link_key'] ?? ''" hint="rg_src; it can’t change once visits came through it." :error="$field('link_key')" wire:model="draft.source.link_key" />
            </div>

            @if ($network)
                <div class="rg-admin-field">
                    <span class="rg-admin-label">Link parameters <span class="rg-admin-traffic__note">Stored with every click. Reports can split by any of them.</span></span>
                    @error('link_params')<p class="rg-admin-games__note rg-admin-games__note--danger">{{ $message }}</p>@enderror
                    <div class="rg-admin-traffic__param-table" role="table" aria-label="Link parameters">
                        <div class="rg-admin-traffic__param-row rg-admin-traffic__param-row--head" role="row"><span>What</span><span>Parameter</span><span>Network token</span><span></span></div>
                        @foreach ($s['link_params'] ?? [] as $index => $param)
                            <div class="rg-admin-traffic__param-row" role="row" wire:key="param-{{ $index }}">
                                <span class="rg-admin-traffic__param-what">
                                    <select class="rg-admin-traffic__select" wire:model="draft.source.link_params.{{ $index }}.kind" aria-label="What parameter {{ $index + 1 }} carries">
                                        @foreach (\App\Enums\TrafficLinkParam::cases() as $kind)
                                            <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                                        @endforeach
                                    </select>
                                </span>
                                <input class="rg-admin-input rg-admin-traffic__mono" type="text" aria-label="Query parameter {{ $index + 1 }}" wire:model="draft.source.link_params.{{ $index }}.param" />
                                <input class="rg-admin-input rg-admin-traffic__mono" type="text" aria-label="Network’s token for parameter {{ $index + 1 }}" wire:model="draft.source.link_params.{{ $index }}.token" />
                                <x-admin.ui.icon-button icon="x" label="Remove parameter {{ $index + 1 }}" variant="ghost" size="sm" wire:click="removeParam({{ $index }})" />
                                @foreach (['label', 'param', 'token', 'kind'] as $part)
                                    @error('link_params.'.$index.'.'.$part)<p class="rg-admin-games__note rg-admin-games__note--danger rg-admin-traffic__params-error">{{ $message }}</p>@enderror
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                    <x-admin.ui.button size="sm" icon="plus" wire:click="addParam">Add parameter</x-admin.ui.button>
                </div>
            @endif

            <div class="rg-admin-traffic__window">
                <div class="rg-admin-field">
                    <span class="rg-admin-label">Attribution window</span>
                    <div class="rg-admin-traffic__views" role="radiogroup" aria-label="Attribution window">
                        @foreach ([1 => '1 day', 2 => '2 days', 3 => '3 days', 7 => '7 days', 30 => '30 days'] as $days => $label)
                            <button type="button" role="radio" aria-checked="{{ (int) ($s['attribution_days'] ?? 7) === $days ? 'true' : 'false' }}" @class(['rg-admin-traffic__view', 'rg-admin-traffic__view--on' => (int) ($s['attribution_days'] ?? 7) === $days]) wire:click="$set('draft.source.attribution_days', {{ $days }})">{{ $label }}</button>
                        @endforeach
                    </div>
                    <span class="rg-admin-traffic__note">A milestone counts for the visitor’s latest click from this source within the window, on any page of the site: a sign-up in the feed an hour later still counts for the click. The click stays with the browser in a first-party cookie. Keep the window no longer than the network accepts conversions.</span>
                </div>
                <div class="rg-admin-field">
                    <span class="rg-admin-label">The network’s day</span>
                    <select class="rg-admin-traffic__select" wire:model="draft.source.reporting_timezone" aria-label="The network’s day">
                        @foreach (array_unique([$s['reporting_timezone'] ?? 'UTC', ...$editor['timezones']]) as $zone)
                            <option value="{{ $zone }}">{{ $zone }}</option>
                        @endforeach
                    </select>
                    @error('reporting_timezone')<p class="rg-admin-games__note rg-admin-games__note--danger">{{ $message }}</p>@enderror
                    <span class="rg-admin-traffic__note">Reports count days as the network does, so figures match its own. A network read through its API reports in its own zone.</span>
                </div>
            </div>
            <div class="rg-admin-traffic__detected">
                <strong>Detected by RateGuru</strong>
                <span class="rg-admin-traffic__note">Device (mobile or desktop), operating system, browser, country, city and language come from the visit itself, so the link needs no parameters for them.</span>
            </div>
        </div>

        <div class="rg-admin-traffic__pane" x-show="pane === 'spend'" x-cloak>
            @php
                $modes = $network
                    ? ['click_cost' => ['The price in each click', 'The network puts the price of every click into the link. Exact per click, at once.'], 'rate' => ['A rate you set', 'You type the price you bid, per device. Clicks × rate, an estimate.'], 'api' => ['The network’s API', 'RateGuru asks the network for its spend every five minutes; until the network has a click’s cost, the click’s own price stands in.'], 'manual' => ['Typed in or a file', 'Type a day’s spend, or import the network’s CSV.'], 'none' => ['Not counted', 'Visits from this source cost nothing in reports.']]
                    : ['fixed' => ['A fixed price', 'A set amount once, a day, a week or a month, spread over the days.'], 'manual' => ['Typed in or a file', 'Type a day’s spend, or import a CSV.'], 'none' => ['Not counted', 'Visits from this source cost nothing in reports.']];
            @endphp
            <fieldset class="rg-admin-field rg-admin-games__radio-rows">
                <legend class="rg-admin-label">Spend comes from</legend>
                @foreach ($modes as $mode => [$name, $help])
                    <label @class(['rg-admin-games__radio-row', 'rg-admin-games__radio-row--on' => ($s['spend_mode'] ?? '') === $mode])>
                        <input type="radio" value="{{ $mode }}" wire:model.live="draft.source.spend_mode" />
                        <span class="rg-admin-traffic__perf-label"><span>{{ $name }}</span><span class="rg-admin-traffic__note">{{ $help }}</span></span>
                    </label>
                @endforeach
                @error('spend_mode')<p class="rg-admin-games__note rg-admin-games__note--danger">{{ $message }}</p>@enderror
            </fieldset>
            @if (($s['spend_mode'] ?? '') === 'rate')
                <div class="rg-admin-traffic__row">
                    <x-admin.ui.text-field label="Mobile ($)" id="rg-admin-source-rate-mobile" :value="(string) ($s['rate_mobile'] ?? '')" :error="$field('rate_mobile')" wire:model="draft.source.rate_mobile" />
                    <x-admin.ui.text-field label="Desktop ($)" id="rg-admin-source-rate-desktop" :value="(string) ($s['rate_desktop'] ?? '')" :error="$field('rate_desktop')" wire:model="draft.source.rate_desktop" />
                    <label class="rg-admin-field">
                        <span class="rg-admin-label">Per</span>
                        <select class="rg-admin-traffic__select" wire:model="draft.source.rate_unit" aria-label="Per">
                            <option value="click">a click</option>
                            <option value="cpm">a thousand</option>
                        </select>
                    </label>
                </div>
            @endif
            @if (($s['spend_mode'] ?? '') === 'fixed')
                <div class="rg-admin-traffic__row">
                    <x-admin.ui.text-field label="Price ($)" id="rg-admin-source-fixed" :value="(string) ($s['fixed_amount'] ?? '')" :error="$field('fixed_amount')" wire:model="draft.source.fixed_amount" />
                    <label class="rg-admin-field">
                        <span class="rg-admin-label">Per</span>
                        <select class="rg-admin-traffic__select" wire:model="draft.source.fixed_period" aria-label="Per">
                            @foreach (['once' => 'once', 'day' => 'a day', 'week' => 'a week', 'month' => 'a month'] as $period => $name)
                                <option value="{{ $period }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
                {{-- Its first day and, if it ends, its last, in one calendar: the end may stay open. --}}
                <x-admin.ui.date-range-field
                    open-end
                    label="Dates"
                    id="rg-admin-source-dates"
                    :from="(string) ($s['fixed_from'] ?? '')"
                    :to="(string) ($s['fixed_to'] ?? '')"
                    from-model="draft.source.fixed_from"
                    to-model="draft.source.fixed_to"
                    hint="The first day it is paid for, and the last if it ends."
                    :error="$field('fixed_from') ?? $field('fixed_to')"
                />
            @endif
            <x-admin.ui.text-field label="Target price of an account ($)" id="rg-admin-source-cpa" :value="(string) ($s['target_cpa'] ?? '')" hint="What you are ready to pay for an account from this source; its campaigns use it unless they have their own." :error="$field('target_cpa')" wire:model="draft.source.target_cpa" />
            @if ($editor['accountPrice'] !== null)
                <div class="rg-admin-field" data-testid="traffic-source-account-price">
                    <span class="rg-admin-label">What an account from it is worth</span>
                    <span class="rg-admin-traffic__price-line">
                        <strong>{{ \App\Support\Traffic\Money\TrafficMoney::brief($editor['accountPrice']['price']) }}</strong>
                        {{-- In a tab of its own, so the editor and its unsaved draft stay as they are. --}}
                        <a class="rg-admin-link" href="{{ $editor['accountPrice']['url'] }}" target="_blank" rel="noopener">Set on Value</a>
                    </span>
                    <span class="rg-admin-traffic__note">{{ $editor['accountPrice']['own'] ? 'Its own account price, in place of the site’s '.\App\Support\Traffic\Money\TrafficMoney::brief($editor['accountPrice']['site']).'.' : 'The site’s account price: it has none of its own.' }} Bonuses and the country group’s worth apply on top.</span>
                </div>
            @endif
        </div>

        @if ($network)
            <div class="rg-admin-traffic__pane" x-show="pane === 'postbacks'" x-cloak>
                <p class="rg-admin-traffic__note">Each rule tells the network of a milestone its clicks reach, once per click, with its worth in {value}. Games set their goals; here you choose which milestones the network hears and under which of its own goal IDs. Send test calls the address with a made-up click ID and writes nothing.</p>
                @php
                    $on = collect($draft['rules'])->filter(fn (array $rule): bool => (bool) ($rule['enabled'] ?? false))->pluck('event');
                    $together = collect($editor['sharedGoals'])->contains(fn (array $events): bool => in_array('Flow goal reached', $events, true) && in_array('New account confirmed', $events, true));
                @endphp
                {{-- Both fire for one click when a game's goal is the account; sent to one goal of the network, the warning below says so instead. --}}
                @if ($on->contains('goal') && $on->contains('account') && ! $together)
                    <x-admin.ui.inline-notice tone="info" data-testid="traffic-rules-goal-and-account">
                        For a game whose goal is a new account, “Flow goal reached” and “New account confirmed” happen together, so the network gets both, each under its own goal ID. Optimise the campaign on one of them in the network.
                    </x-admin.ui.inline-notice>
                @endif
                @foreach ($editor['sharedGoals'] as $events)
                    <x-admin.ui.inline-notice tone="warning" data-testid="traffic-rules-shared-goal">
                        {{ count(array_unique($events)) === 1 ? count($events).' postbacks on “'.$events[0].'”' : \Illuminate\Support\Arr::join($events, ', ', ' and ') }} go to the same goal of the network. It counts each as another conversion of the same click and adds up their values, so one visitor looks like several and their worth is counted more than once. Give each its own goal in the network, or keep one of them.
                    </x-admin.ui.inline-notice>
                @endforeach
                @foreach ($draft['rules'] as $index => $rule)
                    <div class="rg-admin-traffic__rule" wire:key="rule-{{ $index }}">
                        <div class="rg-admin-traffic__rule-head">
                            <label class="rg-admin-field">
                                <span class="rg-admin-label">When</span>
                                <select class="rg-admin-traffic__select" wire:model.live="draft.rules.{{ $index }}.event" aria-label="Event" aria-describedby="rg-admin-rule-when-{{ $index }}">
                                    @foreach ($editor['events'] as $event)
                                        <option value="{{ $event }}">{{ $event === 'goal' ? 'Flow goal reached' : (\App\Enums\TrafficEvent::tryFrom($event)?->label() ?? $event) }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <x-admin.ui.text-field label="Goal ID in the network" :id="'rg-admin-rule-goal-'.$index" :value="(string) ($rule['goal_code'] ?? '')" aria-describedby="rg-admin-rule-when-{{ $index }}" wire:model.live.blur="draft.rules.{{ $index }}.goal_code" />
                            <x-admin.ui.switch label="Status" on="Sending" off="Off" :id="'rg-admin-rule-on-'.$index" :checked="(bool) $rule['enabled']" wire:model.live="draft.rules.{{ $index }}.enabled" />
                        </div>
                        {{-- One line of fields; what the event and the goal ID mean, under them in a few lines. --}}
                        <p class="rg-admin-traffic__note rg-admin-traffic__rule-hint" id="rg-admin-rule-when-{{ $index }}">
                            @if (($rule['event'] ?? '') === 'goal')
                                <span><span class="rg-admin-traffic__rule-term">Flow goal reached</span> is the goal each game’s flow sets — an account, a share, a post opened… — whichever game the click went to.</span>
                            @else
                                <span><span class="rg-admin-traffic__rule-term">When</span> is the milestone that sends it, once per click.</span>
                            @endif
                            <span><span class="rg-admin-traffic__rule-term">Goal ID</span> is the ID the network gave the goal this postback reports; it fills {goal_code}.</span>
                        </p>
                        @if (($rule['event'] ?? '') === 'settled')
                            <label class="rg-admin-field">
                                <span class="rg-admin-label">Only a visit that got at least to</span>
                                <select class="rg-admin-traffic__select" wire:model="draft.rules.{{ $index }}.needs_event" aria-label="Only a visit that got at least to">
                                    @foreach ($editor['floors'] as $floor => $name)
                                        <option value="{{ $floor === 'opened' ? '' : $floor }}">{{ $name }}</option>
                                    @endforeach
                                </select>
                                <span class="rg-admin-traffic__note">A visit settles once — its account confirmed, 30 minutes without a new step, or a day after the click — and tells the network the best worth it reached. A network that counts conversions learns nothing when every visit is one: send only those that got this far. One that bids on value can take every visit that opened the page.</span>
                            </label>
                        @endif
                        <x-admin.ui.text-field label="Address" :id="'rg-admin-rule-url-'.$index" :value="(string) $rule['url_template']" hint="{click_id}, {value}, {value_total}, {event}, {goal_code}, {device}, {game}, {version}, {param:name}" :error="$field('rules.'.$index.'.url_template')" wire:model.live.blur="draft.rules.{{ $index }}.url_template" />
                        <div class="rg-admin-traffic__row">
                            <x-admin.ui.button size="sm" icon="play" wire:click="testRule({{ $index }})">Send test</x-admin.ui.button>
                            <x-admin.ui.button size="sm" variant="ghost" icon="x" wire:click="removeRule({{ $index }})">Remove</x-admin.ui.button>
                        </div>
                    </div>
                @endforeach
                <x-admin.ui.button size="sm" icon="plus" wire:click="addRule">Add postback</x-admin.ui.button>
            </div>

            <div class="rg-admin-traffic__pane" x-show="pane === 'blocking'" x-cloak>
                <div class="rg-admin-field">
                    <span class="rg-admin-label">Block by</span>
                    <x-admin.ui.segmented label="Block by" :options="['site' => 'Site', 'zone' => 'Zone']" :value="$s['block_level'] ?? 'site'" wire:model="draft.source.block_level" compact />
                </div>
                <x-admin.ui.switch label="Block bots and losing sites by itself" id="rg-admin-source-auto" :checked="(bool) ($s['block_auto'] ?? false)" on-hint="Every 5 minutes, through the network’s API, within the day’s cap." off-hint="Only you block, from Report → Sites & zones." wire:model="draft.source.block_auto" />
                @error('block_auto')<p class="rg-admin-games__note rg-admin-games__note--danger">{{ $message }}</p>@enderror

                @if ($editor['thresholds'] !== null)
                    @php
                        $general = $editor['thresholds']['general'];
                        $names = \App\Support\Traffic\Blocking\BlockRule::names();
                        $inputs = [
                            'block_bot_clicks' => ['type' => 'number', 'min' => 1],
                            'block_open_below' => ['type' => 'number', 'min' => 1, 'max' => 100],
                            'block_open_below_average' => ['type' => 'number', 'min' => 1, 'max' => 100],
                            'block_open_floor' => ['type' => 'number', 'min' => 0, 'max' => 100],
                            'block_min_spend' => ['type' => 'text'],
                            'block_min_clicks' => ['type' => 'number', 'min' => 1],
                            'block_roi_below' => ['type' => 'number', 'max' => 0],
                            'block_grace_days' => ['type' => 'number', 'min' => 0, 'max' => 30],
                            'block_target_multiple' => ['type' => 'text'],
                            'block_start_clicks' => ['type' => 'number', 'min' => 1],
                            'block_start_percent' => ['type' => 'number', 'min' => 1, 'max' => 100],
                        ];
                        // Each line of the rule as Report → Worth blocking words it: a threshold where its value goes, with what stands either side of it.
                        $lines = [
                            ['Likely bots', [': at least ', ['block_bot_clicks', '', ''], ' clicks, and fewer opened the page than ', ['block_open_below', '', '%'], ' and than ', ['block_open_below_average', '', '%'], ' of the source’s own average, or than ', ['block_open_floor', '', '%'], ' whatever the average — 0 for no floor.']],
                            ['Losing', [': ROI below ', ['block_roi_below', '', '%'], ' once it has spent ', ['block_target_multiple', '', ''], ' × the target price of an account where its campaign or this source has one — else at least ', ['block_min_spend', '$', ''], ' spent or ', ['block_min_clicks', '', ''], ' clicks — from ', ['block_grace_days', '', ''], ' days after its first click.']],
                            ['Weak start', [': at least ', ['block_start_clicks', '', ''], ' clicks and the game started below ', ['block_start_percent', '', '%'], ' as often as on the source’s other places.']],
                        ];
                    @endphp
                    {{-- The general rule, or the source's own thresholds: an empty one keeps the general value it shows. Back to the general rule empties them all in the draft, so Save clears them; until then they are kept here, and come back on returning to the source's own. --}}
                    <div class="rg-admin-field" data-testid="traffic-source-thresholds" x-data="{ thresholds: @js($editor['thresholds']['own'] ? 'own' : 'general'), keys: @js(\App\Support\Traffic\Blocking\BlockRule::KEYS), kept: {} }" x-init="$watch('thresholds', (value) => keys.forEach((key) => { if (value === 'general') { kept[key] = $wire.get('draft.source.' + key); $wire.$set('draft.source.' + key, null, false) } else if (key in kept) { $wire.$set('draft.source.' + key, kept[key], false) } }))">
                        <span class="rg-admin-label">Thresholds</span>
                        <x-admin.ui.segmented label="Thresholds" :options="['general' => 'General rule', 'own' => 'Own thresholds for this source']" :value="$editor['thresholds']['own'] ? 'own' : 'general'" x-model="thresholds" compact />
                        <div class="rg-admin-traffic__rule" x-bind:class="{ 'rg-admin-traffic__rule--read': thresholds === 'general' }">
                            @foreach ($lines as [$title, $parts])
                                <p class="rg-admin-traffic__rule-line"><strong>{{ $title }}</strong>@foreach ($parts as $part)@if (is_array($part))<strong x-show="thresholds === 'general'">{{ $part[1] }}{{ str_replace('-', '−', $general[$part[0]]) }}{{ $part[2] }}</strong><span x-show="thresholds === 'own'" x-cloak>{{ $part[1] }}<input @class(['rg-admin-input', 'rg-admin-traffic__rule-input', 'rg-admin-input--invalid' => $field($part[0]) !== null]) type="{{ $inputs[$part[0]]['type'] }}" @if ($inputs[$part[0]]['type'] === 'text') inputmode="decimal" @endif @isset($inputs[$part[0]]['min']) min="{{ $inputs[$part[0]]['min'] }}" @endisset @isset($inputs[$part[0]]['max']) max="{{ $inputs[$part[0]]['max'] }}" @endisset placeholder="{{ $general[$part[0]] }}" aria-label="{{ ucfirst($names[$part[0]]) }}" @if ($field($part[0]) !== null) aria-invalid="true" @endif wire:model="draft.source.{{ $part[0] }}" />{{ $part[2] }}</span>@else{{ $part }}@endif @endforeach</p>
                            @endforeach
                            <span class="rg-admin-traffic__note" x-show="thresholds === 'general'">As Report → Worth blocking sets them for every source.</span>
                            <span class="rg-admin-traffic__note" x-show="thresholds === 'own'" x-cloak>An empty field keeps the general rule’s value, shown in it. Saved with the source.</span>
                            @foreach ($inputs as $key => $input)
                                @error($key)<p class="rg-admin-games__note rg-admin-games__note--danger">{{ $message }}</p>@enderror
                            @endforeach
                        </div>
                    </div>
                @endif

                @if ($editor['blocking'] !== null)
                    @php
                        $b = $editor['blocking'];
                        $c = $b['counts'];
                        $r = $b['rule'];
                        $noun = ($s['block_level'] ?? 'site') === 'zone' ? 'zone' : 'site';
                        $percent = fn (int $value): string => ($value < 0 ? '−' : '').abs($value).'%';
                    @endphp
                    {{-- What the source has blocked as saved, and where those blocks stand: the figures the rule and the report wrote. --}}
                    <div class="rg-admin-field" data-testid="traffic-source-blocking-status">
                        <span class="rg-admin-label">Blocked now</span>
                        <div class="rg-admin-traffic__kpis">
                            <div>
                                <span class="rg-admin-traffic__tile-label">In force</span>
                                <strong>{{ number_format($c['sites'] + $c['zones']) }}</strong>
                                <span class="rg-admin-traffic__note">{{ $c['sites'] + $c['zones'] === 0 ? 'Nothing blocked yet' : implode(' · ', array_filter([$c['sites'] > 0 ? number_format($c['sites']).' '.\Illuminate\Support\Str::plural('site', $c['sites']) : null, $c['zones'] > 0 ? number_format($c['zones']).' '.\Illuminate\Support\Str::plural('zone', $c['zones']) : null])) }}</span>
                                <span class="rg-admin-traffic__note">{{ number_format($c['by_hand']) }} by hand · {{ number_format($c['by_rule']) }} by the rule</span>
                            </div>
                            {{-- What the API sent, where an API sends blocks; else only what is marked here, since RateGuru can't see the network's blacklist. --}}
                            @if ($b['connected'] || $c['pending'] + $c['applied'] + $c['failed'] + $c['removing'] > 0)
                                <div>
                                    <span class="rg-admin-traffic__tile-label">Sent through the API</span>
                                    <strong>{{ number_format($c['applied']) }}</strong>
                                    <span class="rg-admin-traffic__note">on the network’s list · {{ number_format($c['pending']) }} waiting · {{ number_format($c['failed']) }} failed</span>
                                    @if ($c['marked'] > 0 || $c['removing'] > 0)
                                        <span class="rg-admin-traffic__note">{{ implode(' · ', array_filter([$c['marked'] > 0 ? number_format($c['marked']).' marked here only, not sent' : null, $c['removing'] > 0 ? number_format($c['removing']).' coming off' : null])) }}</span>
                                    @endif
                                </div>
                            @else
                                <div>
                                    <span class="rg-admin-traffic__tile-label">Marked here</span>
                                    <strong>{{ number_format($c['marked']) }}</strong>
                                    <span class="rg-admin-traffic__note">No API sends these: copy their IDs into the network’s blacklist. RateGuru can’t see that list.</span>
                                </div>
                            @endif
                            <div>
                                <span class="rg-admin-traffic__tile-label">By the rule today</span>
                                <strong>{{ number_format($c['rule_today']) }} of {{ number_format($b['cap']) }}</strong>
                                <span class="rg-admin-traffic__note">{{ $b['auto'] ? 'On for this source' : 'Off for this source' }}</span>
                                <span class="rg-admin-traffic__note">{{ $b['ranAt'] !== null ? 'Last ran '.$b['ranAt']->format('j M H:i') : 'Hasn’t run yet' }}</span>
                            </div>
                        </div>
                        @if ($b['why'] !== [])
                            <x-admin.ui.inline-notice :tone="$b['auto'] ? 'warning' : 'info'" data-testid="traffic-source-blocking-why">
                                {{ $b['auto'] ? 'The rule can’t block for this source by itself:' : 'The rule couldn’t block for this source by itself:' }}
                                <ul class="rg-admin-traffic__block-why">
                                    @foreach ($b['why'] as $reason)
                                        <li>{{ $reason }}</li>
                                    @endforeach
                                </ul>
                            </x-admin.ui.inline-notice>
                        @endif
                        <span class="rg-admin-traffic__block-links">
                            {{-- In a tab of their own, so the editor and its unsaved draft stay as they are. --}}
                            <a class="rg-admin-link" href="{{ $b['sitesUrl'] }}" target="_blank" rel="noopener" data-testid="traffic-source-blocked-sites">See blocked in Sites & zones</a>
                            <a class="rg-admin-link" href="{{ $b['logUrl'] }}" target="_blank" rel="noopener" data-testid="traffic-source-blocks-log">Open the blocks log</a>
                        </span>
                    </div>

                    @php
                        // The thresholds that apply to this source as saved, each of its own marked so.
                        $own = fn (string $key): string => in_array($key, $b['own'], true) ? ' (this source’s)' : '';
                        $share = fn (float $value): string => (abs($value - round($value)) < 0.05 ? number_format($value, 0) : number_format($value, 1)).'%';
                        $average = $b['average'];
                        $minimums = 'at least '.$r['min_spend'].$own('block_min_spend').' spent or '.number_format($r['min_clicks']).' clicks'.$own('block_min_clicks');
                        $multiple = $r['target_multiple'].$own('block_target_multiple').' × the target price of an account';
                        // Below the floor a site is bots whatever the source's average; 0 turns it off, and the step says so.
                        $botFloor = $r['open_floor'] > 0 ? ', or than '.$percent($r['open_floor']).$own('block_open_floor').' always, whatever the average' : ', with no floor'.$own('block_open_floor');
                    @endphp
                    <div class="rg-admin-traffic__preset-card" data-testid="traffic-source-blocking-how">
                        <strong>How blocking works</strong>
                        <ol class="rg-admin-traffic__block-steps">
                            <li>Every 5 minutes the rule reads each {{ $noun }}’s last 30 days. <strong>Likely bots</strong>: at least {{ number_format($r['bot_clicks']) }} clicks{{ $own('block_bot_clicks') }}, and fewer opened the page than {{ $percent($r['open_below']) }}{{ $own('block_open_below') }} and than {{ $r['open_below_average'] }}%{{ $own('block_open_below_average') }} of the source’s own average{{ $botFloor }}{{ match (true) { $average === null => '', $average['rate'] === null => ': no clicks from it in the last 30 days yet, so '.$percent($average['fromFloor'] ? $r['open_floor'] : $r['open_below']).' alone counts', $average['fromFloor'] => ': its clicks opened the page '.$share($average['rate'] * 100).' of the time, so the floor counts, under '.$share($average['below']), default => ': its clicks opened the page '.$share($average['rate'] * 100).' of the time, so under '.$share($average['below']).' counts' } }}.</li>
                            <li><strong>Losing</strong>: ROI below {{ $percent($r['roi_below']) }}{{ $own('block_roi_below') }} once a {{ $noun }} has cost enough — {{ match (true) { $b['target'] !== null => $multiple.': its campaign’s own where it has one, else this source’s '.$b['target']['amount'].', so '.$b['target']['spend'], $b['campaignTargets'] > 0 => $multiple.' where its campaign has one, else '.$minimums, default => $minimums.', as neither this source nor its campaigns have a target price of an account' } }} — from {{ $r['grace_days'] }} {{ \Illuminate\Support\Str::plural('day', $r['grace_days']) }}{{ $own('block_grace_days') }} after its first click, so its accounts can arrive; {{ $b['worth']['known'] ? 'judged now that the worth stands on '.number_format($b['worth']['accounts']).' accounts' : 'and only once the worth is known: '.number_format($b['worth']['accounts']).' of the '.$b['worth']['needed'].' accounts it needs so far' }}.</li>
                            <li>It blocks only those with a click in the last 3 days, the most spent first, up to {{ number_format($b['cap']) }} a day; one you unblock stays unblocked for 30 days. {{ $b['own'] === [] ? 'These are the general thresholds, from Report → Worth blocking: give this source its own under Thresholds above.' : 'Those marked (this source’s) are its own, set under Thresholds above; the rest are the general ones from Report → Worth blocking.' }}</li>
                            <li>You block by hand in Report → Sites & zones: tick them, then Block, or Copy IDs.</li>
                            <li>With the API connected, a block reaches the network within a minute; otherwise copy the IDs into the network’s blacklist.</li>
                        </ol>
                    </div>
                @endif

                @if (! $editor['new'] && $editor['connector'] !== null)
                    <div class="rg-admin-traffic__preset-card">
                        <strong>{{ $editor['connector']['name'] ?? 'API' }} @unless ($editor['connector']['verified'])<x-admin.ui.badge tone="warning">Unverified</x-admin.ui.badge>@endunless</strong>
                        <span class="rg-admin-traffic__note">{{ $editor['connector']['promise'] ?? '' }}</span>
                        @if ($connected)
                            <div class="rg-admin-traffic__row">
                                <x-admin.ui.button size="sm" icon="refresh-cw" wire:click="syncNow">Sync now</x-admin.ui.button>
                                <x-admin.ui.button size="sm" wire:click="disconnectApi">Disconnect</x-admin.ui.button>
                            </div>
                        @endif
                        <div class="rg-admin-traffic__row">
                            <x-admin.ui.text-field :label="$editor['connector']['credential_label'] ?? 'Key'" id="rg-admin-source-secret" type="password" :value="''" :hint="$editor['connector']['credential_help'] ?? null" wire:model="secret" autocomplete="new-password" data-1p-ignore data-lpignore="true" data-bwignore />
                            <x-admin.ui.button size="sm" wire:click="connectApi">{{ $connected ? 'Reconnect' : 'Connect' }}</x-admin.ui.button>
                        </div>
                    </div>
                @endif
            </div>
        @endif
    </div>
    <x-slot:footer>
        <x-admin.ui.segmented label="State" :options="['active' => 'Active', 'paused' => 'Paused', 'ended' => 'Ended']" :value="$s['status'] ?? 'active'" wire:model="draft.source.status" compact />
        <span class="rg-admin-traffic__footer-actions">
            <x-admin.ui.button wire:click="closeEditor">Cancel</x-admin.ui.button>
            <x-admin.ui.button variant="primary" wire:click="saveSource" data-testid="traffic-source-save">Save</x-admin.ui.button>
        </span>
    </x-slot:footer>
</x-admin.ui.drawer>
