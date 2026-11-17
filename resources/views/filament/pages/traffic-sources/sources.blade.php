{{--
    Sources: the networks and placements visits are paid for, each with its
    key, state and API; and Tracking — whether visits are recorded and
    postbacks sent, and the IP database that places visitors.
--}}
@if ($sources === [])
    <x-admin.ui.empty-state icon="megaphone" title="No sources yet">
        Add a network from its preset, or a banner or link bought at a fixed price.
        <x-slot:action>
            <x-admin.ui.button variant="primary" icon="plus" wire:click="startAdding">Add source</x-admin.ui.button>
        </x-slot:action>
    </x-admin.ui.empty-state>
@else
    <div class="rg-admin-table rg-admin-table__scroll">
        <div class="rg-admin-table__grid rg-admin-traffic__grid rg-admin-traffic__grid--6" role="table" aria-label="Sources">
            <div class="rg-admin-table__row rg-admin-table__row--head" role="row">
                @foreach (['Source', 'Key', 'Kind', 'State', 'API', 'Last click'] as $heading)
                    <div class="rg-admin-table__cell" role="columnheader">{{ $heading }}</div>
                @endforeach
            </div>
            @foreach ($sources as $row)
                <div class="rg-admin-table__row" role="row" wire:key="source-{{ $row['id'] }}">
                    <div class="rg-admin-table__cell" role="cell"><button type="button" class="rg-admin-traffic__drill" wire:click="openEditor('{{ $row['id'] }}')">{{ $row['name'] }}</button></div>
                    <div class="rg-admin-table__cell rg-admin-traffic__mono" role="cell">{{ $row['key'] }}</div>
                    <div class="rg-admin-table__cell" role="cell">{{ $row['preset'] }}</div>
                    <div class="rg-admin-table__cell" role="cell"><x-admin.ui.badge :tone="$row['status'] === 'active' ? 'success' : 'neutral'">{{ ucfirst($row['status']) }}</x-admin.ui.badge></div>
                    <div class="rg-admin-table__cell" role="cell">{{ $row['connection'] === null ? '—' : ($labels['connection'][$row['connection']] ?? $row['connection']) }}</div>
                    <div class="rg-admin-table__cell" role="cell">{{ $row['lastClick'] === null ? 'No clicks yet' : \Carbon\CarbonImmutable::parse($row['lastClick'])->diffForHumans() }}</div>
                </div>
            @endforeach
        </div>
    </div>
@endif

<x-admin.ui.card title="Tracking" data-testid="traffic-tracking">
    <x-admin.ui.switch label="Record visits" id="rg-admin-traffic-capture" :checked="(bool) $settings['capture_enabled']" on-hint="Paid clicks and visits are recorded." off-hint="Nothing is recorded and no visitor cookie is set." wire:model="settings.capture_enabled" />
    <x-admin.ui.switch label="Send postbacks" id="rg-admin-traffic-postbacks" :checked="(bool) $settings['postbacks_enabled']" on-hint="Networks are told of the steps their clicks reach." off-hint="Nothing is sent to any network." wire:model="settings.postbacks_enabled" />
    <x-admin.ui.radio-cards label="IP database" :options="[
        'geolite2_city' => ['label' => 'GeoLite2 City', 'help' => 'MaxMind, updated twice a week. The default.'],
        'dbip_city_lite' => ['label' => 'DB-IP City Lite', 'help' => 'DB-IP, updated monthly.'],
    ]" :value="$settings['geo_edition']" wire:model="settings.geo_edition" />
    <p class="rg-admin-traffic__note">
        Serving: {{ $geo['serving']?->label() ?? 'none yet' }}{{ isset($geo['installed']['version']) ? ' · build '.$geo['installed']['version'] : '' }}{{ $geo['error'] !== null ? ' · last error: '.$geo['error'] : '' }}.
        @if ($geo['serving'] === \App\Enums\GeoEdition::DbipCityLite)
            <a href="https://db-ip.com" target="_blank" rel="noopener">IP Geolocation by DB-IP</a>
        @else
            This product includes GeoLite Data created by MaxMind, available from https://www.maxmind.com.
        @endif
    </p>
    <div class="rg-admin-traffic__row">
        <x-admin.ui.button size="sm" icon="refresh-cw" wire:click="refreshGeo">Update now</x-admin.ui.button>
        <x-admin.ui.button variant="primary" wire:click="saveSettings">Save tracking</x-admin.ui.button>
    </div>
</x-admin.ui.card>
