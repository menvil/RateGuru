{{--
    Add source, as the reference draws it: the ad networks from their
    presets, found by name or ad format, each with what its preset fills in
    and what its API can do; any other network; and a banner or link at a
    fixed price. Choosing one opens the editor on a new source.
--}}
@php
    $networks = array_values(array_filter($presetCards, fn (array $preset): bool => $preset['key'] !== 'placement'));
    $placement = collect($presetCards)->firstWhere('key', 'placement');
@endphp
<x-admin.ui.drawer id="rg-admin-traffic-add" wide title="Add source" subtitle="What kind of traffic is it?" x-on:dismiss="$wire.closeAdding()">
    <div class="rg-admin-traffic__drawer" x-data="{ find: '' }">
        <div class="rg-admin-traffic__panel-head">
            <h3 class="rg-admin-traffic__panel-title">Ad network</h3>
            <p class="rg-admin-traffic__note">Clicks you buy. Pick the network’s preset: it fills in the link tokens and the postback address.</p>
        </div>
        <div class="rg-admin-traffic__list-tools">
            <div class="rg-admin-traffic__list-search">
                <x-admin.ui.icon name="search" :size="14" />
                <input type="search" class="rg-admin-input" placeholder="Find a network or an ad format" aria-label="Find a network or an ad format" x-model="find" />
            </div>
            <span class="rg-admin-traffic__note">{{ count(array_filter($networks, fn (array $preset): bool => ! $preset['custom'])) }} presets</span>
        </div>
        <ul class="rg-admin-traffic__presets" data-testid="traffic-presets">
            @foreach ([...$networks, $placement] as $preset)
                @if ($preset['key'] === 'placement')
                    <li class="rg-admin-traffic__panel-head rg-admin-traffic__presets-heading">
                        <h3 class="rg-admin-traffic__panel-title">Banner or link</h3>
                        <p class="rg-admin-traffic__note">A price agreed up front, no click ID.</p>
                    </li>
                @endif
                <li x-show="{{ $preset['key'] === 'placement' || $preset['custom'] ? 'true' : '! find || '.\Illuminate\Support\Js::from(mb_strtolower($preset['name'].' '.$preset['formats'])).'.includes(find.toLowerCase())' }}">
                    <button type="button" class="rg-admin-traffic__preset" wire:click="openEditor('new:{{ $preset['key'] }}')">
                        <span class="rg-admin-traffic__source-sign" aria-hidden="true"><x-admin.ui.icon :name="$preset['key'] === 'placement' ? 'link' : ($preset['custom'] ? 'plus' : 'megaphone')" :size="14" /></span>
                        <span class="rg-admin-traffic__preset-text">
                            <span class="rg-admin-traffic__preset-name">{{ $preset['name'] }}</span>
                            <span class="rg-admin-traffic__note">{{ $preset['formats'] }}</span>
                            @unless ($preset['custom'])
                                <span class="rg-admin-traffic__preset-caps">
                                    @foreach ($preset['chips'] as [$label, $icon, $api])
                                        <span @class(['rg-admin-traffic__preset-cap', 'rg-admin-traffic__preset-cap--api' => $api])><x-admin.ui.icon :name="$icon" :size="10" />{{ $label }}</span>
                                    @endforeach
                                </span>
                            @endunless
                            @if ($preset['note'] !== '')
                                <span class="rg-admin-traffic__note">{{ $preset['note'] }}</span>
                            @endif
                        </span>
                        <x-admin.ui.icon name="chevron-right" :size="14" />
                    </button>
                </li>
            @endforeach
        </ul>
    </div>
</x-admin.ui.drawer>
