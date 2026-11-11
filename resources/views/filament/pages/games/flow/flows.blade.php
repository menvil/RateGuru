{{--
    The flow on screen, and the menu that switches between the game's flows:
    a search over their names and steps; the active ones, each with its steps
    in order and the versions made from it — a link, since switching is a page
    load, so unsaved changes are asked about; the archived ones folded away;
    and a new flow from a template.
--}}
@php
    $active = collect($flows)->where('archived', false)->values();
    $archived = collect($flows)->where('archived', true)->values();
@endphp
<div
    class="rg-admin-games__sets rg-admin-games__flows"
    x-data="{ open: false, archived: false, query: '', shows(text) { return this.query.trim() === '' || text.toLowerCase().includes(this.query.trim().toLowerCase()) } }"
    x-on:keydown.escape="open = false"
    x-on:click.outside="open = false"
>
    <button type="button" class="rg-admin-games__flow-trigger" x-on:click="open = ! open; if (open) $nextTick(() => $refs.search.focus())" x-bind:aria-expanded="open" aria-haspopup="true" aria-controls="rg-admin-game-flow-list">
        <x-admin.ui.icon name="workflow" :size="16" />
        <span class="rg-admin-games__set-text">
            <span class="rg-admin-games__eyebrow">Flow</span>
            <span class="rg-admin-games__set-name">{{ $flowName }}</span>
        </span>
        <span class="rg-admin-games__set-use">{{ $flowUsage }}</span>
        <x-admin.ui.icon name="chevrons-up-down" :size="16" />
    </button>

    <div id="rg-admin-game-flow-list" class="rg-admin-games__set-panel rg-admin-games__flow-panel" x-cloak x-show="open" x-transition.opacity.duration.100ms>
        <div class="rg-admin-games__flow-search">
            <x-admin.ui.search-field label="Search flows or steps" placeholder="Search flows or steps" x-ref="search" x-model="query" />
        </div>
        <div class="rg-admin-games__flow-rows">
            @if ($active->isNotEmpty())
                <p class="rg-admin-games__eyebrow rg-admin-games__flow-head">Active · {{ $active->count() }}</p>
            @endif
            <ul class="rg-admin-games__set-list" aria-label="Flows">
                @foreach ($active as $item)
                    <li x-show="shows(@js($item['name'].' '.$item['chain']))">
                        <a href="{{ $item['url'] }}" @class(['rg-admin-games__set-item', 'rg-admin-games__set-item--current' => $item['current']]) @if ($item['current']) aria-current="true" @endif>
                            <span class="rg-admin-games__set-item-text">
                                <span class="rg-admin-games__set-item-name">{{ $item['name'] }}</span>
                                <span class="rg-admin-games__note">{{ $item['chain'] }}</span>
                                <span class="rg-admin-games__note">{{ $item['usage'] }}</span>
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
                <button type="button" class="rg-admin-games__set-archived-toggle" x-show="! archived && query.trim() === ''" x-on:click="archived = true">
                    <x-admin.ui.icon name="archive" :size="14" />
                    <span>Show archived ({{ $archived->count() }})</span>
                </button>
                <div x-show="archived || query.trim() !== ''">
                    <p class="rg-admin-games__eyebrow rg-admin-games__flow-head">Archived · {{ $archived->count() }}</p>
                    <ul class="rg-admin-games__set-list" aria-label="Archived flows">
                        @foreach ($archived as $item)
                            <li x-show="shows(@js($item['name'].' '.$item['chain']))">
                                <a href="{{ $item['url'] }}" @class(['rg-admin-games__set-item', 'rg-admin-games__set-item--current' => $item['current']]) @if ($item['current']) aria-current="true" @endif>
                                    <span class="rg-admin-games__set-item-text">
                                        <span class="rg-admin-games__set-item-name rg-admin-games__set-item-name--archived">{{ $item['name'] }}</span>
                                        <span class="rg-admin-games__note">{{ $item['chain'] }}</span>
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
                </div>
            @endif
        </div>

        <div class="rg-admin-games__flow-new">
            <label class="rg-admin-games__flow-new-label" for="rg-admin-game-flow-template">New flow from</label>
            <select id="rg-admin-game-flow-template" class="rg-admin-games__select" wire:model="newFlowTemplate">
                @foreach ($templates as $template)
                    <option value="{{ $template['value'] }}">{{ $template['label'] }} — {{ $template['description'] }}</option>
                @endforeach
            </select>
            <x-admin.ui.button size="sm" icon="plus" wire:click="createFlow(false)">New flow</x-admin.ui.button>
        </div>
        <p class="rg-admin-games__flow-foot">{{ $active->count() }} active · {{ $archived->count() }} archived · Copy flow starts a separate flow. Versions keep their own copy, so edits here don’t change live versions.</p>
    </div>
</div>
