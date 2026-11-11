{{--
    The flow down the middle, as the reference draws it: its name — renamed in
    place — and the versions made from it; what keeps it from being
    published; then its steps in order, joined by a line. Each card is its
    kind, who sees it, what it says and its problems, with Move up, Move down
    and Remove (the game stays, the same in every flow); the + on the line
    between two cards, and after the last, opens the steps that fit there.
--}}
@php
    $connector = fn (int $position): array => ['position' => $position, 'last' => $position === count($cards), 'open' => $insertAt === $position, 'types' => $insertAt === $position ? $insertable : []];
@endphp

@if ($stale)
    <x-admin.ui.inline-notice tone="warning">
        Someone saved this flow after you opened it. Reload to see their changes; what you changed here is replaced.
        <x-slot:actions>
            <x-admin.ui.button size="sm" icon="refresh-cw" wire:click="reload">Reload</x-admin.ui.button>
        </x-slot:actions>
    </x-admin.ui.inline-notice>
@endif

@if ($flowArchived)
    <x-admin.ui.inline-notice tone="info">
        This flow is archived. It can’t be published until it is restored.
        <x-slot:actions>
            <x-admin.ui.button size="sm" icon="archive-restore" wire:click="restoreFlow">Restore flow</x-admin.ui.button>
        </x-slot:actions>
    </x-admin.ui.inline-notice>
@endif

<div class="rg-admin-games__flow-title" x-data="{ renaming: false }">
    <template x-if="! renaming">
        <div class="rg-admin-games__flow-title-row">
            <h2 class="rg-admin-games__flow-name">{{ $flowName }}</h2>
            <x-admin.ui.badge :tone="$flowArchived ? 'neutral' : ($flowLive ? 'success' : 'outline')">{{ $flowUsage }}</x-admin.ui.badge>
            <x-admin.ui.icon-button icon="pencil" label="Rename this flow" variant="ghost" size="sm" x-on:click="renaming = true; $nextTick(() => $root.querySelector('input')?.focus())" />
        </div>
    </template>
    <template x-if="renaming">
        <form class="rg-admin-games__flow-title-row" x-on:submit.prevent="$wire.renameFlow(); renaming = false">
            <x-admin.ui.text-field label="Flow name" id="rg-admin-game-flow-name" :value="$flowName" class="rg-admin-games__flow-name-field" wire:model="flowName" />
            <x-admin.ui.button size="sm" type="submit">Done</x-admin.ui.button>
        </form>
    </template>
</div>

@if ($issues !== [])
    <x-admin.ui.inline-notice tone="danger">
        <strong>This flow can’t be published yet.</strong>
        <ul class="rg-admin-games__issues">
            @foreach ($issues as $issue)
                <li>{{ $issue->index >= 0 ? 'Step '.($issue->index + 1).' · ' : '' }}{{ $issue->message }}</li>
            @endforeach
        </ul>
    </x-admin.ui.inline-notice>
@endif

<ol class="rg-admin-games__canvas" aria-label="Steps">
    @foreach ($cards as $card)
        @if ($card['index'] > 0)
            @include('filament.pages.games.flow.insert', $connector($card['index']))
        @endif
        <li @class(['rg-admin-games__step', 'rg-admin-games__step--current' => $card['index'] === $selected, 'rg-admin-games__step--issue' => isset($stepIssues[$card['index']]), 'rg-admin-games__step--game' => $card['game']]) wire:key="step-{{ $card['key'] }}">
            <button type="button" class="rg-admin-games__step-select" wire:click="selectStep({{ $card['index'] }})" @if ($card['index'] === $selected) aria-current="true" @endif>
                <span class="rg-admin-games__step-sign" aria-hidden="true"><x-admin.ui.icon :name="$card['icon']" :size="17" /></span>
                <span class="rg-admin-games__step-text">
                    <span class="rg-admin-games__step-head">
                        <span class="rg-admin-games__step-name">{{ $card['label'] }}</span>
                        @if ($card['audience'])
                            <span class="rg-admin-games__step-chip"><x-admin.ui.icon name="user" :size="11" />{{ $card['audience'] }}</span>
                        @endif
                        @if ($card['game'])
                            <span class="rg-admin-games__step-fixed"><x-admin.ui.icon name="lock" :size="11" />Same in every flow</span>
                        @endif
                    </span>
                    <span class="rg-admin-games__step-summary">{{ $card['summary'] }}</span>
                    @if (isset($stepIssues[$card['index']]))
                        <span class="rg-admin-games__step-error"><x-admin.ui.icon name="circle-alert" :size="12" />{{ implode(' ', $stepIssues[$card['index']]) }}</span>
                    @endif
                </span>
            </button>
            <div class="rg-admin-games__step-tools">
                <x-admin.ui.icon-button icon="arrow-up" :label="'Move '.$card['label'].' up'" variant="ghost" size="sm" :disabled="$card['index'] === 0" wire:click="moveStep({{ $card['index'] }}, -1)" />
                <x-admin.ui.icon-button icon="arrow-down" :label="'Move '.$card['label'].' down'" variant="ghost" size="sm" :disabled="$card['index'] === count($cards) - 1" wire:click="moveStep({{ $card['index'] }}, 1)" />
                @unless ($card['game'])
                    <x-admin.ui.icon-button icon="x" :label="'Remove '.$card['label']" variant="ghost" size="sm" wire:click="removeStep({{ $card['index'] }})" />
                @endunless
            </div>
        </li>
    @endforeach
    @include('filament.pages.games.flow.insert', $connector(count($cards)))
</ol>
