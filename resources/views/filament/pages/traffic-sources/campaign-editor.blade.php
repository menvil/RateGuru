{{--
    A campaign's panel, as the reference draws it: its stage, set at once,
    each with what it means; its results over 30 days and by stage; its
    targeting — reported by the network, or typed in here; its price, budget
    and target against what an account costs now, and how its spend is
    counted, a draft until Save; then its history.
--}}
@php
    $model = $campaign['model'];
    $f = $campaign['figures'];
    $source = $campaign['sourceModel'];
    $target = ($campaignDraft['target_cpa'] ?? '') !== '' ? $campaignDraft['target_cpa'] : $source?->target_cpa;
    $cpa = $f->costPerAccount();
    $stages = [
        'test' => ['Testing', 'flame', 'Broad targeting and a small budget while data comes in.'],
        'optimise' => ['Optimising', 'sliders-horizontal', 'Cutting the sites and zones that lose, tuning bids.'],
        'scale' => ['Scaling', 'arrow-up-right', 'Buying more of what pays: a whitelist and a bigger budget.'],
        'paused' => ['Paused', 'pause', 'Not buying now. History is kept.'],
    ];
    $typed = array_key_exists('countries', $campaignDraft);
    $perVisit = fn (string $bid): string => $money(((float) $bid) / (($campaignDraft['bid_model'] ?? 'click') === 'cpm' ? 1000 : 1), 4);
@endphp
<x-admin.ui.drawer id="rg-admin-traffic-campaign" editor :title="$model->name ?? 'Campaign '.$model->external_id" :subtitle="$campaign['source'].' · campaign '.$model->external_id.' · running since '.$model->first_click_at->format('j M')" wire:key="campaign-{{ $model->id }}" x-on:dismiss="$wire.closeCampaign()">
    <div class="rg-admin-traffic__drawer">
        <div class="rg-admin-traffic__pane">
            <div class="rg-admin-field">
                <span class="rg-admin-label">Stage</span>
                <div class="rg-admin-traffic__stage-cards" role="radiogroup" aria-label="Stage">
                    @foreach ($stages as $key => [$label, $icon, $help])
                        <button type="button" role="radio" aria-checked="{{ $model->stage?->value === $key ? 'true' : 'false' }}" @class(['rg-admin-traffic__stage-card', 'rg-admin-traffic__stage-card--on' => $model->stage?->value === $key]) wire:click="setStage('{{ $key }}')">
                            <strong><x-admin.ui.icon :name="$icon" :size="12" />{{ $label }}</strong>
                            <span class="rg-admin-traffic__note">{{ $help }}</span>
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="rg-admin-field">
                <span class="rg-admin-label">Results by stage</span>
                <div class="rg-admin-traffic__kpis">
                    <div><span class="rg-admin-traffic__tile-label">Visits</span><strong>{{ number_format($f->visits) }}</strong><span class="rg-admin-traffic__note">last 30 days</span></div>
                    <div><span class="rg-admin-traffic__tile-label">Spend</span><strong>{{ $money($f->spend) }}</strong><span class="rg-admin-traffic__note">{{ \App\Support\Traffic\Money\TrafficMoney::of($f->spendEstimated)->isPositive() ? 'incl. '.$money($f->spendEstimated).' estimated' : 'counted' }}</span></div>
                    <div><span class="rg-admin-traffic__tile-label">Accounts</span><strong>{{ number_format($f->accounts) }}</strong><span class="rg-admin-traffic__note">confirmed</span></div>
                    <div><span class="rg-admin-traffic__tile-label">Cost / account</span><strong @class(['rg-admin-traffic__bad' => $cpa !== null && $target !== null && (float) $cpa > (float) $target])>{{ $cpa === null ? '—' : $money($cpa) }}</strong><span class="rg-admin-traffic__note">{{ $target === null ? 'no target' : 'target '.$money($target) }}</span></div>
                    <div><span class="rg-admin-traffic__tile-label">Value</span><strong>{{ $money($f->value()) }}</strong><span class="rg-admin-traffic__note">incl. {{ $money($f->valueExpected) }} expected</span></div>
                    <div><span class="rg-admin-traffic__tile-label">ROI</span><strong @class(['rg-admin-traffic__good' => ($f->roi() ?? 0) > 0, 'rg-admin-traffic__bad' => ($f->roi() ?? 0) < 0])>{{ $f->roi() === null ? '—' : ($f->roi() > 0 ? '+' : '').number_format($f->roi(), 0).'%' }}</strong><span class="rg-admin-traffic__note">value − spend</span></div>
                </div>
                @if ($campaign['stages'] !== [])
                    <div class="rg-admin-traffic__param-table">
                        <div class="rg-admin-traffic__stage-row rg-admin-traffic__param-row--head"><span>Stage</span><span>Dates</span><span>Visits</span><span>Spend</span><span>Value</span><span>ROI</span></div>
                        @foreach ($campaign['stages'] as $stage)
                            @php($sf = $stage['figures'])
                            <div class="rg-admin-traffic__stage-row">
                                <span>{{ $stages[$stage['stage']][0] ?? ucfirst($stage['stage']) }}</span>
                                <span class="rg-admin-traffic__note">{{ $stage['started_at']->format('j M') }} – {{ $stage['ended_at']?->format('j M') ?? 'now' }}</span>
                                <span class="rg-admin-traffic__fig">{{ number_format($sf->visits) }}</span>
                                <span class="rg-admin-traffic__fig">{{ $money($sf->spend) }}</span>
                                <span class="rg-admin-traffic__fig">{{ $money($sf->value()) }}</span>
                                <span @class(['rg-admin-traffic__fig', 'rg-admin-traffic__good' => ($sf->roi() ?? 0) > 0, 'rg-admin-traffic__bad' => ($sf->roi() ?? 0) < 0])>{{ $sf->roi() === null ? '—' : ($sf->roi() > 0 ? '+' : '').number_format($sf->roi(), 0).'%' }}</span>
                            </div>
                        @endforeach
                    </div>
                    <span class="rg-admin-traffic__note">Each stage counts the visits between its dates, so you see whether cutting zones and moving the bid paid off.</span>
                @endif
            </div>

            <div class="rg-admin-field">
                <span class="rg-admin-label">Targeting <span class="rg-admin-traffic__note">{{ $campaign['reported'] ? 'From the '.$campaign['source'].' API'.($model->synced_at !== null ? ' · synced '.$model->synced_at->format('j M H:i') : '') : 'Typed in here' }}</span></span>
                <x-admin.ui.text-field label="Name" id="rg-admin-campaign-name" :value="$campaignDraft['name'] ?? ''" :error="$errors->first('name') ?: null" wire:model="campaignDraft.name" />
                <dl class="rg-admin-traffic__facts-grid">
                    <dt>Countries</dt>
                    <dd class="rg-admin-traffic__chips">
                        @foreach ($typed ? (array) $campaignDraft['countries'] : ($model->countries ?? []) as $code)
                            <span class="rg-admin-traffic__chip">{{ $campaign['countries'][$code] ?? $code }}@if ($typed)<button type="button" aria-label="Take {{ $code }} out" wire:click="toggleCampaignCountry('{{ $code }}')"><x-admin.ui.icon name="x" :size="10" /></button>@endif</span>
                        @endforeach
                        @if ($typed)
                            <select class="rg-admin-traffic__select rg-admin-traffic__chip-add" aria-label="Add a country" x-on:change="$wire.toggleCampaignCountry($event.target.value); $event.target.value = ''">
                                <option value="">+ Country</option>
                                @foreach ($campaign['countries'] as $code => $name)
                                    @continue (in_array($code, (array) $campaignDraft['countries'], true))
                                    <option value="{{ $code }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        @elseif (($model->countries ?? []) === [])
                            <span class="rg-admin-traffic__note">Every country</span>
                        @endif
                    </dd>
                    <dt>Devices</dt>
                    <dd class="rg-admin-traffic__chips">
                        @foreach (['mobile' => ['Mobile', 'smartphone'], 'desktop' => ['Desktop', 'monitor']] as $device => [$label, $icon])
                            @php($on = in_array($device, $typed ? (array) $campaignDraft['devices'] : ($model->devices ?? ['mobile', 'desktop']), true) || ($typed && (array) $campaignDraft['devices'] === []))
                            <button type="button" @class(['rg-admin-traffic__chip rg-admin-traffic__chip--toggle', 'rg-admin-traffic__chip--on' => $on]) @disabled(! $typed) wire:click="toggleCampaignDevice('{{ $device }}')"><x-admin.ui.icon :name="$icon" :size="12" />{{ $label }}</button>
                        @endforeach
                    </dd>
                    <dt>Format</dt>
                    <dd>
                        @if ($typed)
                            <input type="text" class="rg-admin-input" aria-label="Format" placeholder="Popunder, push, native…" wire:model="campaignDraft.format" />
                        @else
                            {{ $model->format ?? '—' }}
                        @endif
                    </dd>
                    <dt>Leads to</dt>
                    <dd>
                        <select class="rg-admin-traffic__select" wire:model="campaignDraft.game_id" aria-label="Game">
                            <option value="">The game in the link the clicks came through</option>
                            @foreach ($campaign['games'] as $id => $title)
                                <option value="{{ $id }}">{{ $title }}</option>
                            @endforeach
                        </select>
                    </dd>
                </dl>
            </div>

            <div class="rg-admin-field">
                <span class="rg-admin-label">Price and spend</span>
                <dl class="rg-admin-traffic__facts-grid">
                    <dt>Pays per</dt>
                    <dd>
                        <div class="rg-admin-traffic__views" role="radiogroup" aria-label="Pays per">
                            @foreach (['click' => 'Click', 'cpm' => '1,000 impressions'] as $key => $label)
                                <button type="button" role="radio" aria-checked="{{ ($campaignDraft['bid_model'] ?? 'click') === $key ? 'true' : 'false' }}" @class(['rg-admin-traffic__view', 'rg-admin-traffic__view--on' => ($campaignDraft['bid_model'] ?? 'click') === $key]) wire:click="$set('campaignDraft.bid_model', '{{ $key }}')">{{ $label }}</button>
                            @endforeach
                        </div>
                    </dd>
                    @foreach (['bid_mobile' => 'Price · mobile', 'bid_desktop' => 'Price · desktop'] as $key => $label)
                        <dt>{{ $label }}</dt>
                        <dd class="rg-admin-traffic__price-line">
                            <span class="rg-admin-traffic__money-field"><span aria-hidden="true">$</span><input type="text" inputmode="decimal" class="rg-admin-input" aria-label="{{ $label }}" wire:model.live.debounce.500ms="campaignDraft.{{ $key }}" /><span class="rg-admin-traffic__note">{{ ($campaignDraft['bid_model'] ?? 'click') === 'cpm' ? 'a thousand' : 'a click' }}</span></span>
                            @if (($campaignDraft[$key] ?? '') !== '' && is_numeric($campaignDraft[$key]))<span class="rg-admin-traffic__note">= {{ $perVisit($campaignDraft[$key]) }} a visit</span>@endif
                        </dd>
                    @endforeach
                    <dt>Budget</dt>
                    <dd class="rg-admin-traffic__price-line"><span class="rg-admin-traffic__money-field"><span aria-hidden="true">$</span><input type="text" inputmode="decimal" class="rg-admin-input" aria-label="Daily budget" wire:model="campaignDraft.daily_budget" /><span class="rg-admin-traffic__note">a day</span></span></dd>
                    <dt>Target</dt>
                    <dd class="rg-admin-traffic__price-line">
                        <span class="rg-admin-traffic__money-field"><span aria-hidden="true">$</span><input type="text" inputmode="decimal" class="rg-admin-input" aria-label="Target price of an account" placeholder="{{ $source?->target_cpa !== null ? rtrim(rtrim((string) $source->target_cpa, '0'), '.') : 'none' }}" wire:model="campaignDraft.target_cpa" /><span class="rg-admin-traffic__note">an account</span></span>
                        <span @class(['rg-admin-traffic__note', 'rg-admin-traffic__bad' => $cpa !== null && $target !== null && (float) $cpa > (float) $target])>{{ ($campaignDraft['target_cpa'] ?? '') === '' ? 'The source’s target.' : 'This campaign’s own target.' }}{{ $cpa !== null && $target !== null ? ' Now '.$money($cpa).' an account, '.((float) $cpa > (float) $target ? 'over' : 'within').' target.' : '' }}</span>
                    </dd>
                </dl>
                @error('target_cpa')<p class="rg-admin-games__note rg-admin-games__note--danger">{{ $message }}</p>@enderror
                <span class="rg-admin-traffic__note">{{ $campaign['reported'] ? 'Change targeting, price and budget in '.$campaign['source'].'; RateGuru reads them at each sync and notes every change in History.' : 'Typed in here for the record; RateGuru never changes them in the network.' }} Target is yours, not the network’s: what you are ready to pay for a confirmed account.</span>
            </div>

            <fieldset class="rg-admin-field rg-admin-games__radio-rows">
                <legend class="rg-admin-label">Spend is counted from</legend>
                @foreach (['' => ['As the source counts it', 'The way '.$campaign['source'].' counts its spend, for this campaign too.'], 'price' => ['This campaign’s price × visits', 'Its price above for each visit, from the day you choose. Earlier days keep theirs.']] as $mode => [$name, $help])
                    <label @class(['rg-admin-games__radio-row', 'rg-admin-games__radio-row--on' => ($campaignDraft['spend_mode'] ?? '') === $mode])>
                        <input type="radio" value="{{ $mode }}" wire:model.live="campaignDraft.spend_mode" />
                        <span class="rg-admin-traffic__perf-label"><span>{{ $name }}</span><span class="rg-admin-traffic__note">{{ $help }}</span></span>
                    </label>
                @endforeach
                @if (($campaignDraft['spend_mode'] ?? '') === 'price')
                    <x-admin.ui.date-time-field date-only label="From" id="rg-admin-campaign-from" :value="(string) ($campaignDraft['price_from'] ?? '')" :quick-picks="['today', 'tomorrow', 'yesterday', 'week-ago', 'month-start']" wire:model="campaignDraft.price_from" />
                @endif
            </fieldset>

            <div class="rg-admin-field">
                <span class="rg-admin-label">History</span>
                @forelse ($campaign['history'] as $event)
                    <div class="rg-admin-traffic__price-change"><span>{{ $event->summary }}</span><span class="rg-admin-traffic__note">{{ $event->occurred_at->format('j M H:i') }}</span></div>
                @empty
                    <p class="rg-admin-traffic__note">Nothing yet.</p>
                @endforelse
            </div>
        </div>
    </div>
    <x-slot:footer>
        @if ($model->status->value === 'archived')
            <x-admin.ui.button icon="archive-restore" wire:click="archiveCampaign(true)">Restore</x-admin.ui.button>
        @else
            <x-admin.ui.button icon="archive" wire:click="archiveCampaign">Archive</x-admin.ui.button>
        @endif
        <span class="rg-admin-traffic__footer-actions">
            <x-admin.ui.button trailing-icon="arrow-right" wire:click="campaignReport">Open in report</x-admin.ui.button>
            <x-admin.ui.button variant="primary" wire:click="saveCampaign">Save</x-admin.ui.button>
        </span>
    </x-slot:footer>
</x-admin.ui.drawer>
