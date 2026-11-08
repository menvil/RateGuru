{{--
    The set's rounds in order, each with whether it can be published, and the
    tools to move, duplicate and remove it; Add round at the foot with how many
    are ready.
--}}
@php($ready = count($roundRows) - $checks->roundsWithIssues())
<ol class="rg-admin-games__round-list" aria-label="Rounds">
    @foreach ($roundRows as $row)
        <li @class(['rg-admin-games__round-row', 'rg-admin-games__round-row--current' => $pane === 'rounds' && $row['index'] === $selected]) wire:key="round-row-{{ $row['index'] }}-{{ $rounds[$row['index']]['key'] }}">
            <button type="button" class="rg-admin-games__round-select" wire:click="selectRound({{ $row['index'] }})" @if ($pane === 'rounds' && $row['index'] === $selected) aria-current="true" @endif>
                <span class="rg-admin-games__round-number">{{ $row['index'] + 1 }}</span>
                <span class="rg-admin-games__round-text">
                    <span class="rg-admin-games__round-name">{{ $row['name'] }}</span>
                    @if ($row['issues'] === [])
                        <span class="rg-admin-games__status rg-admin-games__status--ok">{{ $row['unscored'] ? 'Ready · no points' : 'Complete' }}</span>
                    @else
                        <span class="rg-admin-games__status rg-admin-games__status--issue">{{ count($row['issues']) > 1 ? count($row['issues']).' issues · ' : '' }}{{ $row['issues'][0] }}</span>
                    @endif
                </span>
            </button>
            <x-admin.ui.row-menu :id="'rg-admin-game-round-'.$row['index']" :label="'Actions for '.$row['name']">
                <x-admin.ui.menu-item icon="copy" wire:click="duplicateRound({{ $row['index'] }})">Duplicate</x-admin.ui.menu-item>
                <x-admin.ui.menu-item icon="arrow-up" :disabled="$row['index'] === 0" wire:click="moveRound({{ $row['index'] }}, -1)">Move up</x-admin.ui.menu-item>
                <x-admin.ui.menu-item icon="arrow-down" :disabled="$row['index'] === count($roundRows) - 1" wire:click="moveRound({{ $row['index'] }}, 1)">Move down</x-admin.ui.menu-item>
                <x-admin.ui.menu-item icon="trash-2" tone="danger" wire:click="removeRound({{ $row['index'] }})">Delete</x-admin.ui.menu-item>
            </x-admin.ui.row-menu>
        </li>
    @endforeach
</ol>
<x-admin.ui.button size="sm" icon="plus" wire:click="addRound">Add round</x-admin.ui.button>
<p class="rg-admin-games__note">{{ $ready }} of {{ count($roundRows) }} {{ \Illuminate\Support\Str::plural('round', count($roundRows)) }} ready to publish</p>
