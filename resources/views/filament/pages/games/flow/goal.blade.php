{{--
    The flow's goal, over the canvas, as the reference draws it: the goal in
    force and Change, which opens every goal with whether this flow reaches
    it — or leaves the goal to the steps; what makes it count and on which
    step; and, when nothing in the flow can reach it, the step that would.
--}}
<section
    @class(['rg-admin-games__goal', 'rg-admin-games__goal--missing' => ! $goalCard['reachable']])
    data-testid="game-flow-goal"
    x-data="{ open: false }"
    x-on:click.outside="open = false"
    x-on:keydown.escape.stop="open = false"
>
    <div class="rg-admin-games__goal-head">
        <span class="rg-admin-games__goal-sign" aria-hidden="true"><x-admin.ui.icon name="target" :size="16" /></span>
        <span class="rg-admin-games__goal-title">
            <span class="rg-admin-games__eyebrow">Goal of this flow</span>
            <span class="rg-admin-games__goal-name"><x-admin.ui.icon :name="$goalCard['goal']->icon()" :size="14" />{{ $goalCard['goal']->label() }}</span>
        </span>
        @unless ($flowArchived)
            <button type="button" class="rg-admin-button rg-admin-button--secondary rg-admin-button--sm" aria-haspopup="listbox" x-bind:aria-expanded="open ? 'true' : 'false'" x-on:click="open = ! open" data-testid="game-flow-goal-change">
                Change <x-admin.ui.icon name="chevron-down" :size="14" />
            </button>
            <div class="rg-admin-menu rg-admin-games__goal-menu" role="listbox" aria-label="Goal" x-cloak x-show="open" x-transition.opacity>
                @foreach ($goalCard['choices'] as $choice)
                    @php($picked = $choice['value'] === $goal)
                    <button type="button" role="option" @class(['rg-admin-games__goal-option', 'rg-admin-games__goal-option--on' => $picked]) aria-selected="{{ $picked ? 'true' : 'false' }}" wire:click="$set('goal', @js($choice['value']))" x-on:click="open = false" data-testid="game-flow-goal-option-{{ $choice['value'] === '' ? 'steps' : $choice['value'] }}">
                        <x-admin.ui.icon :name="$choice['icon']" :size="14" class="rg-admin-games__goal-option-icon" />
                        <span class="rg-admin-games__goal-option-text">
                            <span class="rg-admin-games__goal-option-name">{{ $choice['label'] }}</span>
                            <span class="rg-admin-games__goal-option-short">{{ $choice['short'] }}</span>
                            <span @class(['rg-admin-games__goal-option-state', 'rg-admin-games__goal-option-state--needs' => ! $choice['reachable']])>{{ $choice['status'] }}</span>
                        </span>
                        @if ($picked)
                            <x-admin.ui.icon name="check" :size="14" class="rg-admin-games__goal-option-check" />
                        @endif
                    </button>
                @endforeach
                <p class="rg-admin-games__goal-menu-foot">One goal per flow. A published version keeps its goal; a change here counts from the next version you publish.</p>
            </div>
        @endunless
    </div>
    <p class="rg-admin-games__goal-counts">{{ $goalCard['goal']->counts() }}</p>
    @if ($goalCard['reachable'])
        <p class="rg-admin-games__goal-where" data-testid="game-flow-goal-where"><x-admin.ui.icon name="chart-no-axes-column" :size="12" />{{ $goalCard['where'] }} Game analytics, version comparison and postbacks use this goal.</p>
    @else
        <x-admin.ui.inline-notice tone="danger">
            {{ $goalCard['where'] }}
            @if ($goalCard['fix'] !== null && ! $flowArchived)
                <x-slot:actions>
                    <x-admin.ui.button size="sm" icon="plus" wire:click="addGoalStep" data-testid="game-flow-goal-fix">Add {{ $goalCard['fix']['label'] }}</x-admin.ui.button>
                </x-slot:actions>
            @endif
        </x-admin.ui.inline-notice>
    @endif
</section>
