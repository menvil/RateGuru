{{-- The + between two steps, and the steps that fit there once it is open. --}}
<div class="rg-admin-games__insert">
    @if ($chooser['open'])
        <div class="rg-admin-games__insert-panel">
            <span class="rg-admin-games__note">{{ $chooser['types'] === [] ? 'No step fits between these two.' : 'Steps that fit here' }}</span>
            <div class="rg-admin-games__set-tools">
                @foreach ($chooser['types'] as $type)
                    <x-admin.ui.button size="sm" :icon="$type['icon']" wire:click="insertStep('{{ $type['type'] }}', {{ $position }})">{{ $type['label'] }}</x-admin.ui.button>
                @endforeach
                <x-admin.ui.button size="sm" variant="ghost" wire:click="closeInsert">Close</x-admin.ui.button>
            </div>
        </div>
    @else
        <x-admin.ui.icon-button icon="plus" label="Add a step here" variant="ghost" size="sm" wire:click="openInsert({{ $position }})" />
    @endif
</div>
