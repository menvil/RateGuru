{{--
    The flow's steps in order: each card its kind, who sees it, what it says
    and its problems, with Move up, Move down and Remove (the game stays); a
    + between two cards, and after the last, opens the steps that fit there.
--}}
@php($insert = function (int $position) use ($insertAt, $insertable) { return ['open' => $insertAt === $position, 'types' => $insertAt === $position ? $insertable : []]; })
<div class="rg-admin-games__canvas">
    @include('filament.pages.games.flow.insert', ['position' => 0, 'chooser' => $insert(0)])
    @foreach ($cards as $card)
        <article @class(['rg-admin-games__step', 'rg-admin-games__step--current' => $card['index'] === $selected, 'rg-admin-games__step--issue' => isset($stepIssues[$card['index']])]) wire:key="step-{{ $card['key'] }}">
            <button type="button" class="rg-admin-games__step-select" wire:click="selectStep({{ $card['index'] }})" @if ($card['index'] === $selected) aria-current="true" @endif>
                <span class="rg-admin-games__mechanic-icon" aria-hidden="true"><x-admin.ui.icon :name="$card['icon']" :size="16" /></span>
                <span class="rg-admin-games__mechanic-text">
                    <span class="rg-admin-games__mechanic-name">{{ $card['index'] + 1 }}. {{ $card['label'] }}@if ($card['audience']) <x-admin.ui.badge tone="outline">{{ $card['audience'] }}</x-admin.ui.badge>@endif</span>
                    <span class="rg-admin-games__note">{{ $card['summary'] }}</span>
                    @foreach ($stepIssues[$card['index']] ?? [] as $message)
                        <span class="rg-admin-games__status rg-admin-games__status--issue">{{ $message }}</span>
                    @endforeach
                </span>
            </button>
            <div class="rg-admin-games__step-tools">
                <x-admin.ui.icon-button icon="chevron-up" :label="'Move '.$card['label'].' up'" variant="ghost" size="sm" :disabled="$card['index'] === 0" wire:click="moveStep({{ $card['index'] }}, -1)" />
                <x-admin.ui.icon-button icon="chevron-down" :label="'Move '.$card['label'].' down'" variant="ghost" size="sm" :disabled="$card['index'] === count($cards) - 1" wire:click="moveStep({{ $card['index'] }}, 1)" />
                @unless ($card['game'])
                    <x-admin.ui.icon-button icon="trash-2" :label="'Remove '.$card['label']" variant="ghost" size="sm" wire:click="removeStep({{ $card['index'] }})" />
                @endunless
            </div>
        </article>
        @include('filament.pages.games.flow.insert', ['position' => $card['index'] + 1, 'chooser' => $insert($card['index'] + 1)])
    @endforeach
</div>
