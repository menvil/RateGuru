{{-- Add source: a network from its preset, or a banner or link at a fixed price. Choosing one opens the editor on a new source. --}}
<x-admin.ui.drawer id="rg-admin-traffic-add" wide title="Add source" subtitle="A preset fills in the network’s link tokens and postback address." x-on:dismiss="$wire.closeAdding()">
    <div class="rg-admin-traffic__drawer">
    <ul class="rg-admin-traffic__presets" data-testid="traffic-presets">
        @foreach ($presetCards as $preset)
            <li>
                <button type="button" class="rg-admin-traffic__preset" wire:click="openEditor('new:{{ $preset['key'] }}')">
                    <span class="rg-admin-traffic__preset-name">{{ $preset['name'] }}</span>
                    <span class="rg-admin-traffic__note">{{ $preset['formats'] }}{{ $preset['api'] ? ' · API' : '' }}</span>
                </button>
            </li>
        @endforeach
    </ul>
    </div>
</x-admin.ui.drawer>
