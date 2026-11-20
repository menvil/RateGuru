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
            Never past the window's foot: beside the + it rises just as far as it must, never over the top bar; under the
            + on a phone it opens over it instead.
        --}}
        <div
            class="rg-admin-games__connector-panel"
            x-data="{
                fit() {
                    const box = this.$el.getBoundingClientRect()
                    const ceiling = Math.max(0, ...[...document.querySelectorAll('[data-admin-ceiling]')].map((bar) => bar.getBoundingClientRect().bottom)) + 8
                    const floor = Math.min(window.innerHeight, ...[...document.querySelectorAll('[data-admin-floor]')].map((bar) => bar.getBoundingClientRect().top)) - 8
                    const over = box.bottom - floor

                    if (over <= 0) {
                        return
                    }

                    if (window.matchMedia('(max-width: 639.98px)').matches) {
                        this.$el.classList.add('rg-admin-games__connector-panel--up')
                    } else {
                        this.$el.style.translate = `0 ${-Math.max(0, Math.min(over, box.top - ceiling))}px`
                    }
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
