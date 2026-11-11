{{--
    The content set on screen, and the menu that switches between the game's
    sets: each with its rounds and the versions made from it — a link, since
    switching is a page load, so unsaved changes are asked about; the archived
    ones folded away, each with Restore; then this set's name, a copy of it or
    a new empty set, and delete or archive as far as its versions allow, with
    why.
--}}
@php
    $current = collect($sets)->firstWhere('current', true);
    $live = collect($sets)->where('archived', false)->values();
    $archived = collect($sets)->where('archived', true)->reject(fn (array $item): bool => $item['current'])->values();
@endphp
<div
    class="rg-admin-games__sets"
    x-data="{ open: false, archived: false }"
    x-on:keydown.escape="open = false"
    x-on:click.outside="open = false"
>
    <button type="button" class="rg-admin-games__set-trigger" x-on:click="open = ! open; archived = false" x-bind:aria-expanded="open" aria-haspopup="true" aria-controls="rg-admin-game-set-menu">
        <x-admin.ui.icon name="layers" :size="16" />
        <span class="rg-admin-games__set-text">
            <span class="rg-admin-games__eyebrow">Content set</span>
            <span class="rg-admin-games__set-name">{{ $current['name'] ?? $setName }}</span>
            <span class="rg-admin-games__set-use">{{ $current['use'] ?? '' }}</span>
        </span>
        <x-admin.ui.icon name="chevrons-up-down" :size="16" />
    </button>

    <div id="rg-admin-game-set-menu" class="rg-admin-games__set-panel" x-cloak x-show="open" x-transition.opacity.duration.100ms>
        <ul class="rg-admin-games__set-list" aria-label="Content sets">
            @foreach ($live as $item)
                <li>
                    <a href="{{ $item['url'] }}" @class(['rg-admin-games__set-item', 'rg-admin-games__set-item--current' => $item['current']]) @if ($item['current']) aria-current="true" @endif>
                        <span class="rg-admin-games__set-item-text">
                            <span class="rg-admin-games__set-item-name">{{ $item['name'] }}</span>
                            <span class="rg-admin-games__note">{{ $item['use'] }}</span>
                        </span>
                        <span class="rg-admin-games__set-check">
                            @if ($item['current'])
                                <x-admin.ui.icon name="check" :size="16" />
                            @endif
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>

        @if ($archived->isNotEmpty())
            <button type="button" class="rg-admin-games__set-archived-toggle" x-on:click="archived = ! archived" x-bind:aria-expanded="archived">
                <x-admin.ui.icon name="archive" :size="14" />
                <span x-text="(archived ? 'Hide archived' : 'Show archived') + ' · {{ $archived->count() }}'">Show archived · {{ $archived->count() }}</span>
                <x-admin.ui.icon name="chevron-down" :size="14" x-bind:class="{ 'rg-admin-games__set-chevron--up': archived }" />
            </button>
            <ul class="rg-admin-games__set-list" aria-label="Archived content sets" x-cloak x-show="archived">
                @foreach ($archived as $item)
                    <li class="rg-admin-games__set-archived">
                        <span class="rg-admin-games__set-item-text">
                            <span class="rg-admin-games__set-item-name rg-admin-games__set-item-name--archived">{{ $item['name'] }}</span>
                            <span class="rg-admin-games__note">{{ $item['use'] }}</span>
                        </span>
                        <x-admin.ui.button size="sm" icon="rotate-ccw" wire:click="restoreSet({{ $item['id'] }})">Restore</x-admin.ui.button>
                    </li>
                @endforeach
            </ul>
        @endif

        <div class="rg-admin-games__set-manage">
            <x-admin.ui.text-field label="Name of this set" id="rg-admin-game-set-name" :value="$setName" wire:model="setName" x-on:change="$wire.renameSet()" />
            <div class="rg-admin-games__set-tools">
                <x-admin.ui.button size="sm" icon="copy" wire:click="createSet(true)">Copy set</x-admin.ui.button>
                <x-admin.ui.button size="sm" icon="plus" wire:click="createSet(false)">New set</x-admin.ui.button>
                @if ($setManage['delete'] ?? false)
                    <x-admin.ui.button size="sm" icon="trash-2" wire:click="askSet('delete')">Delete set</x-admin.ui.button>
                @endif
                @if ($setManage['archive'] ?? false)
                    <x-admin.ui.button size="sm" icon="archive" wire:click="askSet('archive')">Archive set</x-admin.ui.button>
                @endif
            </div>
            @if ($setManage !== null)
                <p class="rg-admin-games__note rg-admin-games__set-manage-note">{{ $setManage['note'] }}</p>
            @endif
            <p class="rg-admin-games__note">Edits apply to this set only. Versions keep their own copy, so nothing live changes until a new version is published.</p>
        </div>
    </div>
</div>
