{{--
    The line between two steps, or after the last, with its + in the middle;
    once open, the steps that fit there beside it, or why none does.
--}}
<li class="rg-admin-games__connector" aria-label="{{ $last ? 'After step '.$position : 'Between steps '.$position.' and '.($position + 1) }}">
    <span class="rg-admin-games__connector-line" aria-hidden="true"></span>
    <button type="button" @class(['rg-admin-games__connector-add', 'rg-admin-games__connector-add--open' => $open]) wire:click="{{ $open ? 'closeInsert' : 'openInsert('.$position.')' }}" aria-expanded="{{ $open ? 'true' : 'false' }}" title="Add a step here" aria-label="Add a step here">
        <x-admin.ui.icon name="plus" :size="14" />
    </button>
    <span class="rg-admin-games__connector-line" aria-hidden="true"></span>
    @if ($open)
        <div class="rg-admin-games__connector-panel" x-data x-on:click.outside="$wire.closeInsert()">
            <p class="rg-admin-games__note rg-admin-games__connector-title">{{ $types === [] ? ($last ? 'No step fits after the last one.' : 'No step fits between these two.') : 'Steps that fit here' }}</p>
            @foreach ($types as $type)
                <button type="button" class="rg-admin-games__connector-option" wire:click="insertStep('{{ $type['type'] }}', {{ $position }})">
                    <x-admin.ui.icon :name="$type['icon']" :size="15" />
                    {{ $type['label'] }}
                </button>
            @endforeach
        </div>
    @endif
</li>
