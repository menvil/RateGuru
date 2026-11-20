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
        {{--
            Always between the bars kept at the window's top and foot, scrolling inside when it is taller than the room it
            has: beside the + it moves up or down just as far as it must; under the + on a phone, it opens over it when the
            room under it is too short and the room over it longer.
        --}}
        <div
            class="rg-admin-games__connector-panel"
            x-data="{
                fit() {
                    const panel = this.$el
                    const ceiling = Math.max(0, ...[...document.querySelectorAll('[data-admin-ceiling]')].map((bar) => bar.getBoundingClientRect().bottom)) + 8
                    const floor = Math.min(window.innerHeight, ...[...document.querySelectorAll('[data-admin-floor]')].map((bar) => bar.getBoundingClientRect().top)) - 8

                    if (window.matchMedia('(max-width: 639.98px)').matches) {
                        {{-- Under the + its top is 16 below the +'s middle; over it, its foot is 16 above. --}}
                        const top = panel.getBoundingClientRect().top
                        const below = floor - top
                        const above = top - 32 - ceiling
                        const up = panel.offsetHeight > below && above > below

                        panel.classList.toggle('rg-admin-games__connector-panel--up', up)
                        panel.style.maxHeight = `${Math.max(0, up ? above : below)}px`

                        return
                    }

                    panel.style.maxHeight = `${Math.max(0, floor - ceiling)}px`
                    const box = panel.getBoundingClientRect()
                    const shift = box.bottom > floor ? floor - box.bottom : (box.top < ceiling ? ceiling - box.top : 0)

                    panel.style.translate = shift === 0 ? '' : `0 ${Math.round(shift)}px`
                },
            }"
            x-init="$nextTick(() => fit())"
            x-on:click.outside="$wire.closeInsert()"
        >
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
