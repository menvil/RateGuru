{{--
    The game's flows, each a link with its steps and the versions made from
    it; then rename, a new flow from a template, a copy of this one, and
    archive or delete — delete only for a flow no version used.
--}}
<div class="rg-admin-games__sets" x-data="{ open: false }" x-on:keydown.escape="open = false" x-on:click.outside="open = false">
    <button type="button" class="rg-admin-games__set-trigger" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-haspopup="true">
        <span class="rg-admin-games__set-name">{{ $flowName }}</span>
        <span class="rg-admin-games__note">{{ count($cards) }} {{ \Illuminate\Support\Str::plural('step', count($cards)) }}</span>
        <x-admin.ui.icon name="chevrons-up-down" :size="14" />
    </button>

    <div class="rg-admin-games__set-panel" x-cloak x-show="open">
        <ul class="rg-admin-games__set-list">
            @foreach ($flows as $item)
                <li>
                    <a href="{{ $item['url'] }}" @class(['rg-admin-games__set-item', 'rg-admin-games__set-item--current' => $item['current']]) @if ($item['current']) aria-current="true" @endif>
                        <span class="rg-admin-games__set-item-name">{{ $item['name'] }}@if ($item['archived']) · archived @endif</span>
                        <span class="rg-admin-games__note">{{ $item['chain'] }}@if ($item['versions'] > 0) · used in {{ $item['versions'] }} {{ \Illuminate\Support\Str::plural('version', $item['versions']) }}@endif</span>
                    </a>
                </li>
            @endforeach
        </ul>
        <div class="rg-admin-games__set-tools">
            <x-admin.ui.text-field label="Flow name" id="rg-admin-game-flow-name" :value="$flowName" wire:model="flowName" />
            <x-admin.ui.button size="sm" wire:click="renameFlow">Rename</x-admin.ui.button>
        </div>
        <div class="rg-admin-games__set-tools">
            <x-admin.ui.text-field label="New flow" id="rg-admin-game-new-flow" placeholder="Email Before Result" :value="$newFlowName" wire:model="newFlowName" />
        </div>
        <label class="rg-admin-field">
            <span class="rg-admin-label">Start from</span>
            <select class="rg-admin-games__select" wire:model="newFlowTemplate">
                @foreach ($templates as $template)
                    <option value="{{ $template['value'] }}">{{ $template['label'] }} — {{ $template['description'] }}</option>
                @endforeach
            </select>
        </label>
        <div class="rg-admin-games__set-tools">
            <x-admin.ui.button size="sm" icon="plus" wire:click="createFlow(false)">New flow</x-admin.ui.button>
            <x-admin.ui.button size="sm" icon="copy" wire:click="createFlow(true)">Copy this flow</x-admin.ui.button>
        </div>
        <p class="rg-admin-games__note">Published versions keep their own copy, so edits here never change a live version.</p>
        <div class="rg-admin-games__set-tools">
            @unless ($flowArchived)
                <x-admin.ui.button size="sm" icon="archive" wire:click="askFlow('archive')">Archive flow</x-admin.ui.button>
            @endunless
            <x-admin.ui.button size="sm" variant="ghost" icon="trash-2" :disabled="$flowUsed" wire:click="askFlow('delete')" :title="$flowUsed ? 'A published version was made from this flow' : null">Delete flow</x-admin.ui.button>
        </div>
    </div>
</div>
