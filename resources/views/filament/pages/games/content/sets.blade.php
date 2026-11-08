{{--
    The content set switcher: every set of the game, each a link (switching is
    a page load, so unsaved changes are asked about), with its rounds and the
    versions made from it; then rename, a new set or a copy, and archive or
    delete — delete only for a set no version used.
--}}
<div class="rg-admin-games__sets" x-data="{ open: false }" x-on:keydown.escape="open = false" x-on:click.outside="open = false">
    <button type="button" class="rg-admin-games__set-trigger" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-haspopup="true">
        <span class="rg-admin-games__set-name">{{ $setName }}</span>
        <span class="rg-admin-games__note">{{ count($rounds) }} {{ \Illuminate\Support\Str::plural('round', count($rounds)) }}</span>
        <x-admin.ui.icon name="chevrons-up-down" :size="14" />
    </button>

    <div class="rg-admin-games__set-panel" x-cloak x-show="open">
        <ul class="rg-admin-games__set-list">
            @foreach ($sets as $item)
                <li>
                    <a href="{{ $item['url'] }}" @class(['rg-admin-games__set-item', 'rg-admin-games__set-item--current' => $item['current']]) @if ($item['current']) aria-current="true" @endif>
                        <span class="rg-admin-games__set-item-name">{{ $item['name'] }}@if ($item['archived']) · archived @endif</span>
                        <span class="rg-admin-games__note">{{ $item['rounds'] }} {{ \Illuminate\Support\Str::plural('round', $item['rounds']) }}@if ($item['versions'] > 0) · used in {{ $item['versions'] }} {{ \Illuminate\Support\Str::plural('version', $item['versions']) }}@endif</span>
                    </a>
                </li>
            @endforeach
        </ul>

        <div class="rg-admin-games__set-tools">
            <x-admin.ui.text-field label="Set name" id="rg-admin-game-set-name" :value="$setName" wire:model="setName" />
            <x-admin.ui.button size="sm" wire:click="renameSet">Rename</x-admin.ui.button>
        </div>
        <div class="rg-admin-games__set-tools">
            <x-admin.ui.text-field label="New set" id="rg-admin-game-new-set" placeholder="Quick 3" :value="$newSetName" wire:model="newSetName" />
            <x-admin.ui.button size="sm" icon="plus" wire:click="createSet(false)">New set</x-admin.ui.button>
            <x-admin.ui.button size="sm" icon="copy" wire:click="createSet(true)">Copy this set</x-admin.ui.button>
        </div>
        <p class="rg-admin-games__note">Edits apply to this set only. Published versions keep their own copy, so nothing live changes until a new version is published.</p>
        <div class="rg-admin-games__set-tools">
            @unless ($setArchived)
                <x-admin.ui.button size="sm" icon="archive" wire:click="askSet('archive')">Archive set</x-admin.ui.button>
            @endunless
            <x-admin.ui.button size="sm" variant="ghost" icon="trash-2" :disabled="$setUsed" wire:click="askSet('delete')" :title="$setUsed ? 'A published version was made from this set' : null">Delete set</x-admin.ui.button>
        </div>
    </div>
</div>
