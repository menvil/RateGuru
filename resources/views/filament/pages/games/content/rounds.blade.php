{{--
    The set's rounds in order under their count and Add round: each with its
    number, its name and whether it can be published, and the tools to
    duplicate, move and remove it. A round is moved by dragging it onto the
    round whose place it takes — a line marks where it goes — or with Move up
    and Move down. At the foot, how many are ready.
--}}
@php($ready = count($roundRows) - $checks->roundsWithIssues())
<div class="rg-admin-games__rounds-head">
    <span class="rg-admin-games__rounds-title">Rounds <span class="rg-admin-games__rounds-count">· {{ count($roundRows) }}</span></span>
    <x-admin.ui.button size="sm" icon="plus" wire:click="addRound">Add round</x-admin.ui.button>
</div>

@if ($roundRows === [])
    <p class="rg-admin-games__note rg-admin-games__rounds-empty">No rounds yet.</p>
@else
    <ol class="rg-admin-games__round-list" aria-label="Rounds" x-data="{ dragging: null, over: null }" x-on:dragend="dragging = null; over = null">
        @foreach ($roundRows as $row)
            @php($current = $pane === 'rounds' && $row['index'] === $selected)
            <li
                @class(['rg-admin-games__round-row', 'rg-admin-games__round-row--current' => $current])
                wire:key="round-row-{{ $row['index'] }}-{{ $rounds[$row['index']]['key'] }}"
                draggable="true"
                x-on:dragstart="dragging = {{ $row['index'] }}; $event.dataTransfer.effectAllowed = 'move'; $event.dataTransfer.setData('text/plain', '{{ $row['index'] }}')"
                x-on:dragover.prevent="over = {{ $row['index'] }}"
                x-on:drop.prevent="if (dragging !== null && dragging !== {{ $row['index'] }}) { $wire.dropRound(dragging, {{ $row['index'] }}) } dragging = null; over = null"
                x-bind:class="{ 'rg-admin-games__round-row--dragging': dragging === {{ $row['index'] }}, 'rg-admin-games__round-row--drop': dragging !== null && dragging !== {{ $row['index'] }} && over === {{ $row['index'] }} }"
            >
                <span class="rg-admin-games__round-grip" title="Drag to reorder"><x-admin.ui.icon name="grip-vertical" :size="16" /></span>
                <button type="button" class="rg-admin-games__round-select" wire:click="selectRound({{ $row['index'] }})" @if ($current) aria-current="true" @endif>
                    <span class="rg-admin-games__round-number">{{ $row['index'] + 1 }}</span>
                    <span class="rg-admin-games__round-text">
                        <span class="rg-admin-games__round-name">{{ $row['name'] }}</span>
                        @if ($row['issues'] === [])
                            <span class="rg-admin-games__status rg-admin-games__status--ok"><x-admin.ui.icon name="circle-check" :size="12" />{{ $row['unscored'] ? 'Ready · no points' : 'Complete' }}</span>
                        @else
                            <span class="rg-admin-games__status rg-admin-games__status--issue"><x-admin.ui.icon name="circle-alert" :size="12" />{{ count($row['issues']) > 1 ? count($row['issues']).' issues · ' : '' }}{{ $row['issues'][0] }}</span>
                        @endif
                    </span>
                </button>
                <x-admin.ui.row-menu :id="'rg-admin-game-round-'.$row['index']" :label="'Actions for '.$row['name']">
                    <x-admin.ui.menu-item icon="copy" wire:click="duplicateRound({{ $row['index'] }})">Duplicate</x-admin.ui.menu-item>
                    <x-admin.ui.menu-item icon="arrow-up" :disabled="$row['index'] === 0" wire:click="moveRound({{ $row['index'] }}, -1)">Move up</x-admin.ui.menu-item>
                    <x-admin.ui.menu-item icon="arrow-down" :disabled="$row['index'] === count($roundRows) - 1" wire:click="moveRound({{ $row['index'] }}, 1)">Move down</x-admin.ui.menu-item>
                    <div class="rg-admin-menu__separator" role="separator"></div>
                    <x-admin.ui.menu-item icon="trash-2" tone="danger" wire:click="removeRound({{ $row['index'] }})">Delete</x-admin.ui.menu-item>
                </x-admin.ui.row-menu>
            </li>
        @endforeach
    </ol>
@endif

<div class="rg-admin-games__rounds-foot">
    <span @class(['rg-admin-games__rounds-ready', 'rg-admin-games__rounds-ready--none' => $roundRows === [], 'rg-admin-games__rounds-ready--all' => $roundRows !== [] && $ready === count($roundRows)])>{{ $roundRows === [] ? 'No rounds yet' : $ready.' of '.count($roundRows).' '.\Illuminate\Support\Str::plural('round', count($roundRows)).' ready to publish' }}</span>
    <span class="rg-admin-games__note">Drag rows to reorder, or use Move up and Move down.</span>
</div>
