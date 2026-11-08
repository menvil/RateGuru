{{--
    The step library: every kind of step, whether the flow has it, and — for
    one it lacks — Add, which puts it where it fits best; a step that fits
    nowhere says so.
--}}
<x-admin.ui.card title="Steps" description="Each kind of step once. Game is required.">
    <ul class="rg-admin-games__library">
        @foreach ($library as $item)
            <li class="rg-admin-games__library-item">
                <span class="rg-admin-games__mechanic-icon" aria-hidden="true"><x-admin.ui.icon :name="$item['icon']" :size="16" /></span>
                <span class="rg-admin-games__mechanic-text">
                    <span class="rg-admin-games__mechanic-name">{{ $item['label'] }}</span>
                    <span class="rg-admin-games__note">{{ $item['description'] }}</span>
                </span>
                @if ($item['present'])
                    <x-admin.ui.icon name="check" :size="16" label="In this flow" />
                @elseif ($item['fits'])
                    <x-admin.ui.button size="sm" icon="plus" wire:click="insertStep('{{ $item['type'] }}')">Add<span class="rg-admin-sr-only"> {{ $item['label'] }}</span></x-admin.ui.button>
                @else
                    <span class="rg-admin-games__note" title="Doesn’t fit this flow">—</span>
                @endif
            </li>
        @endforeach
    </ul>
</x-admin.ui.card>
