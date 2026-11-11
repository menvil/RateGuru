{{--
    The step library, as the reference draws it: every kind of step, each a
    row — its sign, its name and a line on what it does — that adds a step the
    flow lacks where it fits best. A step already in the flow is ticked; one
    that fits nowhere is greyed, its reason on hover.
--}}
<p class="rg-admin-games__eyebrow rg-admin-games__library-head">Steps</p>
<ul class="rg-admin-games__library">
    @foreach ($library as $item)
        @php($addable = ! $item['present'] && $item['fits'])
        <li>
            <button
                type="button"
                @class(['rg-admin-games__library-item', 'rg-admin-games__library-item--off' => ! $item['present'] && ! $item['fits']])
                title="{{ $item['tip'] }}"
                @if ($addable) wire:click="insertStep('{{ $item['type'] }}')" @else aria-disabled="true" @endif
            >
                <span class="rg-admin-games__library-sign" aria-hidden="true"><x-admin.ui.icon :name="$item['icon']" :size="15" /></span>
                <span class="rg-admin-games__library-text">
                    <span class="rg-admin-games__library-name">{{ $item['label'] }}</span>
                    <span class="rg-admin-games__library-line">{{ $item['description'] }}</span>
                </span>
                @if ($item['present'])
                    <x-admin.ui.icon name="circle-check" :size="15" class="rg-admin-games__library-state rg-admin-games__library-state--in" label="In this flow" />
                @elseif ($addable)
                    <x-admin.ui.icon name="plus" :size="15" class="rg-admin-games__library-state" :label="'Add '.$item['label']" />
                @else
                    <x-admin.ui.icon name="minus" :size="15" class="rg-admin-games__library-state" :label="$item['tip']" />
                @endif
            </button>
        </li>
    @endforeach
</ul>
