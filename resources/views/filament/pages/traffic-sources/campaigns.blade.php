{{--
    Campaigns, as the reference draws it: the newest campaign that came in by
    itself, to set up; how a campaign gets here; the link to paste for a
    source and a game; a pasted link read the way the server reads a click,
    with samples to try; the latest clicks, live; the networks' campaigns by
    source, in their stages — targeting, stage, price and budget, block
    lists and the period's figures — each opening its panel; and the banners
    and links bought at a fixed price, against what they brought.
--}}
@php
    $stages = ['all' => 'All', 'test' => 'Testing', 'optimise' => 'Optimising', 'scale' => 'Scaling', 'paused' => 'Paused', 'new' => 'New', 'archived' => 'Archived'];
    $stageTone = ['test' => 'outline', 'optimise' => 'warning', 'scale' => 'success', 'paused' => 'neutral', 'new' => 'warning', 'archived' => 'neutral'];
@endphp

@if ($newCampaign !== null)
    <div class="rg-admin-traffic__new-campaign" data-testid="traffic-new-campaign">
        <span class="rg-admin-traffic__new-campaign-sign" aria-hidden="true"><x-admin.ui.icon name="sparkles" :size="16" /></span>
        <span class="rg-admin-traffic__new-campaign-text">
            <span class="rg-admin-traffic__new-campaign-title">{{ $newCampaign['source'] }} campaign {{ $newCampaign['name'] !== null ? '“'.$newCampaign['name'].'”' : $newCampaign['external'] }} is new</span>
            @if ($newCampaign['synced'])
                <span class="rg-admin-traffic__new-campaign-note">{{ number_format($newCampaign['visits']) }} {{ \Illuminate\Support\Str::plural('visit', $newCampaign['visits']) }} since {{ $newCampaign['since']->format('j M H:i') }}{{ $newCampaign['game'] !== null ? ', from the '.$newCampaign['source'].' link of '.$newCampaign['game'] : '' }}. Its clicks and postbacks already work, and its name, targeting, bid and budget come from the {{ $newCampaign['source'] }} API. Choose its stage — Testing, Optimising or Scaling — to take it out of New; a game and a target price of an account are optional.</span>
            @else
                <span class="rg-admin-traffic__new-campaign-note">{{ number_format($newCampaign['visits']) }} {{ \Illuminate\Support\Str::plural('visit', $newCampaign['visits']) }} since {{ $newCampaign['since']->format('j M H:i') }}{{ $newCampaign['game'] !== null ? ', from the '.$newCampaign['source'].' link of '.$newCampaign['game'] : '' }}. Its clicks and postbacks already work; give it a name, its targeting, bid and stage so reports can tell it apart.</span>
            @endif
        </span>
        <x-admin.ui.button size="sm" variant="primary" wire:click="openCampaign({{ $newCampaign['id'] }})">{{ $newCampaign['synced'] ? 'Choose its stage' : 'Set it up' }}</x-admin.ui.button>
    </div>
@endif

<div class="rg-admin-traffic__how">
    <section class="rg-admin-traffic__panel">
        <header class="rg-admin-traffic__panel-head">
            <h2 class="rg-admin-traffic__panel-title">How a campaign gets here</h2>
            <p class="rg-admin-traffic__note">You don’t add campaigns one by one. Each network gets one link per game; you paste it into every campaign you run there.</p>
        </header>
        <ol class="rg-admin-traffic__steps">
            <li><span class="rg-admin-traffic__step-number">1</span><span><strong>One link per network</strong><span class="rg-admin-traffic__note">Get it on the right. It carries the network’s key and its tokens for click, campaign, site, zone and cost.</span></span></li>
            <li><span class="rg-admin-traffic__step-number">2</span><span><strong>New IDs show up by themselves</strong><span class="rg-admin-traffic__note">The first click with a new campaign ID adds the campaign here. Its clicks and postbacks work at once.</span></span></li>
            <li><span class="rg-admin-traffic__step-number">3</span><span><strong>Targeting, bid and stage</strong><span class="rg-admin-traffic__note">Read from the network API where there is one, typed in where there isn’t. Every change lands in History.</span></span></li>
        </ol>
    </section>

    <section class="rg-admin-traffic__panel" data-testid="traffic-get-link">
        <header class="rg-admin-traffic__panel-head">
            <h2 class="rg-admin-traffic__panel-title">Get the link</h2>
        </header>
        @if ($linkSources === [])
            <p class="rg-admin-traffic__note">Add a network or a banner under Sources to get its link.</p>
        @elseif ($games === [])
            <p class="rg-admin-traffic__note">Add a game to get its link: every link opens one game.</p>
        @else
            <div class="rg-admin-traffic__link-pickers">
                {{-- The keys are ids PHP made integers; the chosen ids are strings. --}}
                <select class="rg-admin-traffic__select" wire:model.live="linkSource" aria-label="Source">
                    @foreach ($linkSources as $id => $name)
                        <option value="{{ $id }}" @selected((string) $id === $linkSourceId)>{{ $name }}</option>
                    @endforeach
                </select>
                <select class="rg-admin-traffic__select" wire:model.live="linkGame" aria-label="Game">
                    @foreach ($games as $id => $title)
                        <option value="{{ $id }}" @selected((string) $id === $linkGameId)>{{ $title }}</option>
                    @endforeach
                </select>
            </div>
            <p class="rg-admin-traffic__mono rg-admin-traffic__link" data-testid="traffic-link">{{ $link }}</p>
            <div class="rg-admin-traffic__link-foot">
                {{--
                    The link rides in an attribute: Blade compiles no directive inside a component's attribute.
                    Says Link copied only once the clipboard took it: the write can be refused, and there is no clipboard outside a secure page.
                --}}
                <x-admin.ui.button size="sm" icon="copy" :data-copy="$link" x-data x-on:click="async () => { try { await navigator.clipboard.writeText($el.dataset.copy); $dispatch('rg-admin-toast', { message: 'Link copied', tone: 'success' }) } catch { $dispatch('rg-admin-toast', { message: 'The browser refused the clipboard, so nothing was copied.', tone: 'error' }) } }">Copy link</x-admin.ui.button>
                <span class="rg-admin-traffic__note">The same link for every {{ $linkSourceName }} campaign of this game. rg_src={{ $linkKey }} names the source; its tokens fill in the campaign, site, zone and cost.</span>
            </div>
        @endif
    </section>
</div>

<div class="rg-admin-traffic__pair-even">
    <section class="rg-admin-traffic__panel" data-testid="traffic-check-link">
        <header class="rg-admin-traffic__panel-head">
            <h2 class="rg-admin-traffic__panel-title">Check a link</h2>
            <p class="rg-admin-traffic__note">Paste the link from a campaign, or a click as it arrived, and see how RateGuru reads it: which network, which game, which campaign.</p>
        </header>
        <input type="text" class="rg-admin-input rg-admin-traffic__mono" placeholder="https://…/games/…?rg_src=…" aria-label="A link to check" wire:model.live.debounce.500ms="checkLink" />
        @if ($samples !== [])
            <div class="rg-admin-traffic__samples">
                @foreach ($samples as $label => $sample)
                    <button type="button" class="rg-admin-traffic__sample" wire:click="$set('checkLink', @js($sample))">{{ $label }}</button>
                @endforeach
            </div>
        @endif
        @if ($inspection !== null)
            <div class="rg-admin-traffic__inspection" data-testid="traffic-link-check">
                <x-admin.ui.badge :tone="match ($inspection->mode) { 'click' => 'success', 'template' => 'outline', 'mixed' => 'warning', default => 'danger' }">{{ ['template' => 'Link to paste', 'click' => 'A real click', 'mixed' => 'Tokens left', 'none' => 'Unpaid', 'bad' => 'Not a link'][$inspection->mode] }}</x-admin.ui.badge>
                <dl class="rg-admin-traffic__inspection-fields">
                    @foreach ($inspection->fields as $field)
                        <div><dt><span class="rg-admin-traffic__mono">{{ $field['param'] }}</span> · {{ $field['label'] }}</dt><dd>{{ $field['value'] }} <span class="rg-admin-traffic__note">{{ $field['state'] }}</span></dd></div>
                    @endforeach
                </dl>
                @foreach ($inspection->warnings as $warning)
                    <x-admin.ui.inline-notice :tone="in_array($warning['tone'], ['danger', 'warning', 'success'], true) ? $warning['tone'] : 'info'">{{ $warning['text'] }}</x-admin.ui.inline-notice>
                @endforeach
            </div>
        @endif
    </section>

    <section class="rg-admin-traffic__panel" data-testid="traffic-latest-clicks" wire:poll.30s.visible>
        <header class="rg-admin-traffic__perf-head">
            <div>
                <h2 class="rg-admin-traffic__panel-title">Latest clicks</h2>
                <p class="rg-admin-traffic__note">Every network link, newest first. Start a campaign and its first clicks show up here within a minute, already under its ID.</p>
            </div>
            <span class="rg-admin-traffic__live"><span class="rg-admin-traffic__live-dot"></span>Live</span>
        </header>
        @forelse ($latestClicks as $click)
            <div class="rg-admin-traffic__click">
                <span class="rg-admin-traffic__note">{{ $click['at']->format('H:i') }}</span>
                <span class="rg-admin-traffic__click-text">
                    <span class="rg-admin-traffic__perf-title">{{ $click['name'] }}</span>
                    <span class="rg-admin-traffic__mono rg-admin-traffic__note">{{ $click['query'] }}</span>
                </span>
                <span class="rg-admin-traffic__note">{{ $click['where'] }}</span>
            </div>
        @empty
            <p class="rg-admin-traffic__note">No paid click yet. Paste a source’s link into a campaign and its first clicks show here.</p>
        @endforelse
    </section>
</div>

<section class="rg-admin-traffic__panel" data-testid="traffic-campaigns">
    <header class="rg-admin-traffic__perf-head">
        <div>
            <h2 class="rg-admin-traffic__panel-title">Ad network campaigns</h2>
            <p class="rg-admin-traffic__note">Targeting, bid and budget as set in the network, the stage you are in, and what each campaign earned. Click one for its stages, lists and history.</p>
        </div>
        <div class="rg-admin-traffic__segments" role="radiogroup" aria-label="Stage">
            @foreach ($stages as $key => $label)
                @continue ($key !== 'all' && ($stageCounts[$key] ?? 0) === 0 && $stage !== $key)
                <button type="button" role="radio" aria-checked="{{ $stage === $key ? 'true' : 'false' }}" @class(['rg-admin-traffic__segment', 'rg-admin-traffic__segment--on' => $stage === $key]) wire:click="$set('stage', '{{ $key }}')">{{ $label }}<span class="rg-admin-traffic__segment-count">{{ $stageCounts[$key] ?? 0 }}</span></button>
            @endforeach
        </div>
    </header>

    @if ($campaignGroups === [])
        <x-admin.ui.empty-state icon="megaphone" title="No campaigns here">A campaign shows here by itself with the first click that names it.</x-admin.ui.empty-state>
    @else
        <div class="rg-admin-traffic__perf rg-admin-table__scroll">
            <div class="rg-admin-traffic__perf-grid rg-admin-traffic__perf-grid--campaigns" role="table" aria-label="Ad network campaigns">
                <div class="rg-admin-traffic__camp-row rg-admin-traffic__perf-row--head" role="row">
                    @foreach (['Campaign', 'Targeting', 'Stage', 'Price · budget', 'Lists', 'Visits', 'Spend', 'Value', 'Cost / account', 'ROI', ''] as $heading)
                        <span role="columnheader">{{ $heading }}</span>
                    @endforeach
                </div>
                @foreach ($campaignGroups as $group)
                    <div class="rg-admin-traffic__camp-group" role="row">
                        <span role="cell"><x-admin.ui.icon name="megaphone" :size="12" /><strong>{{ $group['source'] }}</strong><span class="rg-admin-traffic__note">{{ $group['note'] }}</span></span>
                    </div>
                    @foreach ($group['rows'] as $row)
                        @php($f = $row['figures'])
                        {{-- A row a pointer opens anywhere; its name is the button the keyboard and a screen reader open it by, the click bubbling to the row. --}}
                        <div class="rg-admin-traffic__camp-row rg-admin-traffic__camp-row--open" role="row" wire:click="openCampaign({{ $row['id'] }})" wire:key="campaign-{{ $row['id'] }}" data-testid="traffic-campaign-row">
                            <span role="cell" class="rg-admin-traffic__perf-label">
                                <button type="button" class="rg-admin-traffic__camp-open" aria-label="Open campaign {{ $row['name'] }}"><span @class(['rg-admin-traffic__perf-title', 'rg-admin-traffic__warn' => $row['new']])>{{ $row['name'] }}</span></button>
                                <span class="rg-admin-traffic__note">{{ $row['sub'] }}</span>
                            </span>
                            <span role="cell" class="rg-admin-traffic__perf-label">
                                <span>{{ $row['countries'] ?? ($row['new'] && ! $row['synced'] ? 'Not set yet' : 'Every country') }}</span>
                                <span class="rg-admin-traffic__note">{{ $row['new'] && ! $row['synced'] ? 'First seen '.$row['since']->format('j M H:i') : implode(' · ', array_filter([$row['devices'], $row['format']])) }}</span>
                            </span>
                            <span role="cell" class="rg-admin-traffic__perf-label">
                                <x-admin.ui.badge :tone="$stageTone[$row['stage']] ?? 'outline'">{{ $stages[$row['stage']] ?? ucfirst($row['stage']) }}</x-admin.ui.badge>
                                <span class="rg-admin-traffic__note">since {{ $row['since']->format('j M') }}</span>
                            </span>
                            <span role="cell" class="rg-admin-traffic__perf-label">
                                <span>{{ $row['price'] ?? '—' }}</span>
                                <span class="rg-admin-traffic__note">{{ $row['budget'] }}</span>
                            </span>
                            <span role="cell" class="rg-admin-traffic__perf-label">
                                <span>Blocked · {{ $row['blocked'] }}</span>
                                <span class="rg-admin-traffic__note">{{ $row['whitelist'] !== null ? 'Whitelist '.$row['whitelist'] : 'No whitelist' }}</span>
                            </span>
                            <span role="cell" class="rg-admin-traffic__fig"><strong>{{ number_format($f->visits) }}</strong></span>
                            <span role="cell" class="rg-admin-traffic__fig"><strong>{{ $money($f->spend) }}</strong></span>
                            <span role="cell" class="rg-admin-traffic__fig"><strong>{{ $money($f->value()) }}</strong>@if (\App\Support\Traffic\Money\TrafficMoney::of($f->valueExpected)->isPositive())<small>{{ $money($f->valueExpected) }} expected</small>@endif</span>
                            @php($over = $row['target'] !== null && $f->costPerAccount() !== null && (float) $f->costPerAccount() > (float) $row['target'])
                            <span role="cell" @class(['rg-admin-traffic__fig', 'rg-admin-traffic__bad' => $over, 'rg-admin-traffic__good' => ! $over && $f->costPerAccount() !== null && $row['target'] !== null])><strong>{{ $f->costPerAccount() === null ? '—' : $money($f->costPerAccount()) }}</strong><small>{{ $row['target'] === null ? 'no target' : 'target '.$money($row['target']).' · '.$row['targetFrom'] }}</small></span>
                            <span role="cell" @class(['rg-admin-traffic__fig', 'rg-admin-traffic__good' => ($f->roi() ?? 0) > 0, 'rg-admin-traffic__bad' => ($f->roi() ?? 0) < 0])><strong>{{ $f->roi() === null ? '—' : ($f->roi() > 0 ? '+' : '').number_format($f->roi(), 0).'%' }}</strong></span>
                            <span role="cell">@if ($row['toBlock'] > 0)<x-admin.ui.badge tone="danger">{{ $row['toBlock'] }} to block</x-admin.ui.badge>@endif</span>
                        </div>
                    @endforeach
                @endforeach
            </div>
        </div>
        @if ($campaignsMore > 0)
            <p class="rg-admin-traffic__note">The {{ $campaignCap }} newest are shown; {{ $campaignsMore }} older {{ \Illuminate\Support\Str::plural('campaign', $campaignsMore) }} {{ $campaignsMore === 1 ? 'isn’t' : 'aren’t' }}. Choose a source or a stage to narrow them down.</p>
        @endif
        @if ($archivedHidden > 0)
            <p class="rg-admin-traffic__note">{{ $archivedHidden }} archived {{ \Illuminate\Support\Str::plural('campaign', $archivedHidden) }} hidden here; their results stay in reports for the dates they ran. A campaign with no clicks for 30 days is archived by itself, and comes back with its next click.</p>
        @endif
    @endif
</section>

@if ($placements !== [])
    <section class="rg-admin-traffic__panel" data-testid="traffic-placements">
        <header class="rg-admin-traffic__panel-head">
            <h2 class="rg-admin-traffic__panel-title">Banners & links</h2>
            <p class="rg-admin-traffic__note">A price agreed up front — a channel post, a month of a banner. What it brought in is set against that price, so you see when it has paid for itself.</p>
        </header>
        @foreach ($placements as $placement)
            <div class="rg-admin-traffic__placement">
                <span class="rg-admin-traffic__perf-label">
                    <span class="rg-admin-traffic__perf-title">{{ $placement['name'] }}</span>
                    <span class="rg-admin-traffic__note">{{ $placement['sub'] }}</span>
                </span>
                <span class="rg-admin-traffic__placement-bar">
                    <span class="rg-admin-traffic__bar-head">
                        <span>{{ number_format($placement['visits']) }} visits brought {{ $money($placement['value']) }} of {{ $money($placement['paid']) }} paid</span>
                        <strong @class(['rg-admin-traffic__good' => ($placement['ratio'] ?? 0) >= 1])>{{ $placement['ratio'] === null ? 'No price yet' : (($placement['ratio'] >= 1 ? 'Paid back · +' : 'Not paid back · ').number_format(abs($placement['ratio'] - 1) * 100, 0).'%') }}</strong>
                    </span>
                    <span class="rg-admin-traffic__bar-track"><span @class(['rg-admin-traffic__bar-fill', 'rg-admin-traffic__bar-fill--good' => ($placement['ratio'] ?? 0) >= 1]) style="width: {{ min(100, round(($placement['ratio'] ?? 0) * 100, 1)) }}%"></span></span>
                </span>
                <x-admin.ui.button size="sm" trailing-icon="arrow-right" wire:click="reportFor({{ $placement['id'] }})">Report</x-admin.ui.button>
            </div>
        @endforeach
    </section>
@endif
