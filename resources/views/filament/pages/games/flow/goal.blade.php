{{--
    The flow's goal, over the canvas: chosen from the goals, each with whether
    this flow reaches it, or left to follow the steps; what makes it count and
    on which step; and, when nothing in the flow can reach it, the step that
    would.
--}}
<x-admin.ui.card title="Goal of this flow" class="rg-admin-games__goal" data-testid="game-flow-goal">
    <x-slot:aside>
        <x-admin.ui.badge :tone="$goalCard['reachable'] ? 'success' : 'danger'">{{ $goalCard['goal']->label() }}</x-admin.ui.badge>
    </x-slot:aside>
    <label class="rg-admin-field">
        <span class="rg-admin-label">Goal</span>
        <select class="rg-admin-games__select" wire:model.live="goal" data-testid="game-flow-goal-select">
            @foreach ($goalCard['options'] as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
    </label>
    <p class="rg-admin-games__note">{{ $goalCard['goal']->counts() }}</p>
    @if ($goalCard['reachable'])
        <p class="rg-admin-games__note" data-testid="game-flow-goal-where">{{ $goalCard['where'] }} Game analytics, version comparison and postbacks use this goal.</p>
    @else
        <x-admin.ui.inline-notice tone="danger">
            {{ $goalCard['where'] }}
            @if ($goalCard['fix'] !== null)
                <x-slot:actions>
                    <x-admin.ui.button size="sm" icon="plus" wire:click="addGoalStep" data-testid="game-flow-goal-fix">Add {{ $goalCard['fix']['label'] }}</x-admin.ui.button>
                </x-slot:actions>
            @endif
        </x-admin.ui.inline-notice>
    @endif
    <p class="rg-admin-games__note">One goal per flow. A published version keeps its goal; a change here counts from the next version you publish.</p>
</x-admin.ui.card>
