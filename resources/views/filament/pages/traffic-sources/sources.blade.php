{{--
    Sources, as the reference draws it: the ad networks, then the banners
    and links — each with its preset, key, API, blocks and age, how its
    postbacks stand, where its spend comes from and its clicks over 30 days,
    opening its editor or its report — then the unpaid channels RateGuru
    recognises by itself; how a visit gets its source, how a postback works;
    and Tracking — whether visits are recorded and postbacks sent, and the
    IP database that places visitors.
--}}
@php
    $section = function (string $title, string $icon, string $note, ?array $add, array $rows) {
        return compact('title', 'icon', 'note', 'add', 'rows');
    };
    $sections = [
        $section('Ad networks', 'megaphone', 'Bought clicks. The link carries the network’s click ID, campaign, site and zone, so reports split by them and postbacks go back.', ['Add network', 'startAdding'], $networks),
        $section('Banners & links', 'link', 'A banner, a partner site, a channel post or a newsletter. A tracking link and a fixed price; no click ID, so no postbacks.', ['Add banner or link', "openEditor('new:placement')"], $placementRows),
    ];
@endphp

<section class="rg-admin-traffic__panel" data-testid="traffic-sources-list">
    <header class="rg-admin-traffic__perf-head">
        <div>
            <h2 class="rg-admin-traffic__panel-title">Sources</h2>
            <p class="rg-admin-traffic__note">Ad networks and banners you set up yourself, and unpaid traffic {{ site_name() }} recognises on its own. Every visit belongs to exactly one of them.</p>
        </div>
        <x-admin.ui.button size="sm" variant="primary" icon="plus" wire:click="startAdding">Add source</x-admin.ui.button>
    </header>
    <div class="rg-admin-traffic__perf">
        @foreach ($sections as $part)
            <div class="rg-admin-traffic__source-section">
                <span><x-admin.ui.icon :name="$part['icon']" :size="12" /><strong>{{ strtoupper($part['title']) }}</strong><span class="rg-admin-traffic__note">{{ $part['note'] }}</span></span>
                <x-admin.ui.button size="sm" icon="plus" wire:click="{{ $part['add'][1] }}">{{ $part['add'][0] }}</x-admin.ui.button>
            </div>
            @forelse ($part['rows'] as $row)
                <div class="rg-admin-traffic__source-line" wire:key="source-{{ $row['id'] }}" data-testid="traffic-source-row">
                    <span class="rg-admin-traffic__source-sign" aria-hidden="true"><x-admin.ui.icon :name="$part['icon']" :size="14" /></span>
                    <span class="rg-admin-traffic__perf-label">
                        <span class="rg-admin-traffic__source-title"><button type="button" class="rg-admin-traffic__text-button rg-admin-traffic__perf-title" wire:click="openEditor('{{ $row['id'] }}')">{{ $row['name'] }}</button> <x-admin.ui.badge :tone="$row['status'] === 'active' ? 'success' : 'neutral'">{{ ucfirst($row['status']) }}</x-admin.ui.badge></span>
                        <span class="rg-admin-traffic__note">{{ $row['sub'] }}</span>
                    </span>
                    <span class="rg-admin-traffic__perf-label">
                        <span @class(['rg-admin-traffic__method-name', 'rg-admin-traffic__bad' => $row['postbacks']['tone'] === 'bad'])><x-admin.ui.icon :name="$row['postbacks']['icon']" :size="12" /><span>{{ $row['postbacks']['text'] }}</span></span>
                        <span class="rg-admin-traffic__note">{{ $row['postbacks']['sub'] }}</span>
                    </span>
                    <span class="rg-admin-traffic__perf-label">
                        <span class="rg-admin-traffic__method-name"><x-admin.ui.icon :name="$row['spend']['icon']" :size="12" /><span>{{ $row['spend']['text'] }}</span></span>
                        <span class="rg-admin-traffic__note">{{ $row['spendStatus'] }}</span>
                    </span>
                    <span class="rg-admin-traffic__note rg-admin-traffic__source-count">{{ number_format($row['clicks']) }} {{ $row['unit'] }} · 30 days</span>
                    <x-admin.ui.icon-button icon="chart-no-axes-column" :label="'Report of '.$row['name']" variant="ghost" size="sm" wire:click="reportFor({{ $row['id'] }})" />
                    <x-admin.ui.button size="sm" icon="sliders-horizontal" wire:click="openEditor('{{ $row['id'] }}')">Edit</x-admin.ui.button>
                </div>
            @empty
                <p class="rg-admin-traffic__note rg-admin-traffic__source-empty">None yet.</p>
            @endforelse
        @endforeach
        <div class="rg-admin-traffic__source-section">
            <span><x-admin.ui.icon name="sparkles" :size="12" /><strong>UNPAID</strong><span class="rg-admin-traffic__note">Found automatically: shared results, links on other sites, search, direct visits and {{ site_name() }} itself.</span></span>
        </div>
        @foreach ($channels as $channel)
            <div class="rg-admin-traffic__source-line" wire:key="channel-{{ $channel['key'] }}">
                <span class="rg-admin-traffic__source-sign" aria-hidden="true"><x-admin.ui.icon :name="$channel['icon']" :size="14" /></span>
                <span class="rg-admin-traffic__perf-label"><span class="rg-admin-traffic__perf-title">{{ $channel['name'] }}</span><span class="rg-admin-traffic__note">Recognised automatically</span></span>
                <span class="rg-admin-traffic__perf-label"><span class="rg-admin-traffic__method-name"><x-admin.ui.icon name="search" :size="12" />How it’s recognised</span><span class="rg-admin-traffic__note">{{ $channel['how'] }}</span></span>
                <span class="rg-admin-traffic__perf-label"><span class="rg-admin-traffic__method-name"><x-admin.ui.icon name="award" :size="12" />Free</span><span class="rg-admin-traffic__note">Counted and valued, never paid for</span></span>
                <span class="rg-admin-traffic__note rg-admin-traffic__source-count">{{ number_format($channel['visits']) }} visits · 30 days</span>
                <span></span>
                <span></span>
            </div>
        @endforeach
    </div>
</section>

<div class="rg-admin-traffic__pair-even">
    <section class="rg-admin-traffic__panel">
        <header class="rg-admin-traffic__panel-head">
            <h2 class="rg-admin-traffic__panel-title">How a visit gets its source</h2>
            <p class="rg-admin-traffic__note">Checked in this order; the first match wins. Milestones count for the latest paid click within that source’s attribution window, otherwise for the visit’s own source.</p>
        </header>
        <ol class="rg-admin-traffic__steps">
            <li><span class="rg-admin-traffic__step-number">1</span><span><strong>Source key in the link</strong><span class="rg-admin-traffic__note">rg_src names an ad network or a banner. Ad networks add their click ID, campaign, site and zone.</span></span></li>
            <li><span class="rg-admin-traffic__step-number">2</span><span><strong>A shared result</strong><span class="rg-admin-traffic__note">Opened from a share page or a shared result link: Shared results.</span></span></li>
            <li><span class="rg-admin-traffic__step-number">3</span><span><strong>The referrer</strong><span class="rg-admin-traffic__note">A search engine: Search. A {{ site_name() }} page: Inside {{ site_name() }}. Any other site: Other sites.</span></span></li>
            <li><span class="rg-admin-traffic__step-number">4</span><span><strong>Nothing at all</strong><span class="rg-admin-traffic__note">No key and no referrer: Direct.</span></span></li>
        </ol>
    </section>
    <section class="rg-admin-traffic__panel">
        <header class="rg-admin-traffic__panel-head">
            <h2 class="rg-admin-traffic__panel-title">How a postback works</h2>
            <p class="rg-admin-traffic__note">The same for every source.</p>
        </header>
        <ol class="rg-admin-traffic__steps">
            <li><span class="rg-admin-traffic__step-number">1</span><span><strong>Click</strong><span class="rg-admin-traffic__note">The network opens the game link with its click ID, campaign, site, zone and cost in the address.</span></span></li>
            <li><span class="rg-admin-traffic__step-number">2</span><span><strong>Stored</strong><span class="rg-admin-traffic__note">{{ site_name() }} keeps the click with the visitor, their device and country, and later with their account.</span></span></li>
            <li><span class="rg-admin-traffic__step-number">3</span><span><strong>Milestone</strong><span class="rg-admin-traffic__note">The visitor starts, reaches a round, finishes or reaches the flow’s goal.</span></span></li>
            <li><span class="rg-admin-traffic__step-number">4</span><span><strong>Postback</strong><span class="rg-admin-traffic__note">Once per click and milestone, {{ site_name() }} calls the network’s address with the click ID and the value.</span></span></li>
        </ol>
    </section>
</div>

<x-admin.ui.card title="Tracking" data-testid="traffic-tracking">
    <x-admin.ui.switch label="Record visits" id="rg-admin-traffic-capture" :checked="(bool) $settings['capture_enabled']" on-hint="Paid clicks and visits are recorded." off-hint="Nothing is recorded and no visitor cookie is set." wire:model.live="settings.capture_enabled" />
    {{-- Counts only with Record visits, so it is off and can't be turned on while that is off. --}}
    <x-admin.ui.switch label="Record site visits" id="rg-admin-traffic-site-capture" :checked="(bool) $settings['capture_enabled'] && (bool) ($settings['site_capture_enabled'] ?? false)" :disabled="! $settings['capture_enabled']" on-hint="Feed, posts, profiles and pages, one visit per browser every 30 minutes. Needs Record visits." off-hint="Feed, posts, profiles and pages, one visit per browser every 30 minutes. Needs Record visits." wire:model="settings.site_capture_enabled" />
    <x-admin.ui.switch label="Send postbacks" id="rg-admin-traffic-postbacks" :checked="(bool) $settings['postbacks_enabled']" on-hint="Networks are told of the steps their clicks reach." off-hint="Nothing is sent to any network." wire:model="settings.postbacks_enabled" />
    <x-admin.ui.radio-cards label="IP database" :options="[
        'geolite2_city' => ['label' => 'GeoLite2 City', 'help' => 'MaxMind, updated twice a week. The default.'],
        'dbip_city_lite' => ['label' => 'DB-IP City Lite', 'help' => 'DB-IP, updated monthly.'],
    ]" :value="$settings['geo_edition']" wire:model="settings.geo_edition" />
    <p class="rg-admin-traffic__note">
        Serving: {{ $geo['serving']?->label() ?? 'none yet' }}{{ isset($geo['installed']['version']) ? ' · build '.$geo['installed']['version'] : '' }}{{ $geo['error'] !== null ? ' · last error: '.$geo['error'] : '' }}.
        @if ($geo['credit']['url'] !== null)
            <a href="{{ $geo['credit']['url'] }}" target="_blank" rel="noopener">{{ $geo['credit']['text'] }}</a>
        @else
            {{ $geo['credit']['text'] }}
        @endif
    </p>
    <p class="rg-admin-traffic__note" data-testid="traffic-tracking-networks">
        {{ 'Networks: '.implode(' · ', array_filter([
            isset($ipNetworks['installed']['version']) ? 'DB-IP ASN Lite, build '.$ipNetworks['installed']['version'] : 'DB-IP ASN Lite, none yet',
            $ipNetworks['error'] !== null ? 'last error: '.$ipNetworks['error'] : null,
            ($ipNetworks['hosting'] === null ? 'no hosting list yet' : number_format($ipNetworks['hosting']).' hosting and VPN networks').($ipNetworks['hostingError'] !== null ? ' (last error: '.$ipNetworks['hostingError'].')' : ''),
            ($ipNetworks['relay'] === null ? 'no Private Relay list yet' : number_format($ipNetworks['relay']).' Private Relay ranges').($ipNetworks['relayError'] !== null ? ' (last error: '.$ipNetworks['relayError'].')' : ''),
        ])).'. A visit from a hosting or VPN network is flagged, never refused.' }}
        <a href="{{ $ipNetworks['credit']['url'] }}" target="_blank" rel="noopener">{{ $ipNetworks['credit']['text'] }}</a>
    </p>
    <div class="rg-admin-traffic__row">
        <x-admin.ui.button size="sm" icon="refresh-cw" wire:click="refreshGeo">Update now</x-admin.ui.button>
        <x-admin.ui.button variant="primary" wire:click="saveSettings">Save tracking</x-admin.ui.button>
    </div>
</x-admin.ui.card>
