{{--
    The language a visitor reads the site in, among those the project offers;
    nothing at all where it offers only one. Compact beside the small controls
    of a side panel or a game's bar: 32px to the eye and 40px to the finger,
    naming the language by its code. Its list opens from the trigger's end
    edge, or from its start when the trigger sits at the start of its row, on
    a page of either direction.
--}}
@props(['compact' => false, 'align' => 'end'])
@php
    // Only the languages this project offers; an installed but withheld one
    // is neither listed nor accepted by locale.change.
    $locales = app(\App\Support\Locale\LocaleManager::class);
    $enabled = $locales->enabled();
    $current = app()->getLocale();
@endphp

@if ($locales->offersChoice())
{{-- Opened from its start edge, after a long site name, the list can reach past the far side of a narrow screen: it is drawn back by that much and no more. --}}
<div
    x-data="{
        open: false,
        drawnBack: 0,
        keepOnScreen() {
            const trigger = this.$refs.trigger.getBoundingClientRect();
            const width = this.$refs.list.offsetWidth;
            const rtl = getComputedStyle(this.$refs.trigger).direction === 'rtl';
            const past = rtl ? 8 - (trigger.right - width) : trigger.left + width - (document.documentElement.clientWidth - 8);
            this.drawnBack = Math.max(0, past);
        },
    }"
    class="relative shrink-0"
    @click.outside="open = false"
    @keydown.escape.window="open = false"
    data-testid="language-switcher"
>
    <button
        type="button"
        x-ref="trigger"
        @click="open = !open"
        aria-label="{{ __('ui.a11y.switch_language') }}"
        x-bind:aria-expanded="open.toString()"
        data-testid="locale-switcher-trigger"
        @class([
            'h-10 rounded-rgControl bg-rg-card px-2 text-[13px] hover:bg-rg-card2 sm:px-3' => ! $compact,
            'h-8 rounded-rgSm bg-rg-card2 px-2 text-xs before:absolute before:-inset-1' => $compact,
            'relative inline-flex cursor-pointer items-center gap-1 border border-rg-border2 font-medium text-rg-text2 transition-colors hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent focus-visible:ring-offset-2 focus-visible:ring-offset-rg-bg',
        ])
    >
        @isset($enabled[$current])
            {{-- The flag drawn a little larger than the text beside it. Scaled
                 rather than set in a larger font, so neither the control nor
                 the header around it changes width or height. --}}
            <span aria-hidden="true" class="inline-block scale-125 leading-none" data-testid="locale-flag">{{ $enabled[$current]['flag'] }}</span>
        @endisset
        @if ($compact)
            <span>{{ strtoupper($current) }}</span>
        @else
            {{-- In the header's row the full name is the least important of its
                 optional controls, and the code stands in for it while it is put
                 away (resources/js/header-fit.js); elsewhere, and until that has
                 run, the breakpoint decides. --}}
            <span class="sm:hidden" data-header-fit-short="locale-name" data-header-fit-display="inline">{{ strtoupper($current) }}</span>
            <span class="hidden sm:inline" data-header-fit="1" data-header-fit-long="locale-name" data-header-fit-display="inline">{{ $enabled[$current]['native'] ?? strtoupper($current) }}</span>
        @endif
        <svg @class(['fill-current opacity-60', 'size-3' => ! $compact, 'size-2.5' => $compact]) viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
    </button>

    <div
        x-ref="list"
        x-cloak
        x-show="open"
        @if ($align === 'start')
            x-effect="if (open) $nextTick(() => keepOnScreen())"
            x-bind:style="{ marginInlineStart: (-drawnBack) + 'px' }"
        @endif
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        data-testid="locale-switcher-menu"
        @class([
            'absolute z-50 mt-1 max-h-[calc(100dvh-5rem)] w-max min-w-32 max-w-[calc(100vw-2rem)] overflow-y-auto rounded-rgCard border border-rg-border bg-rg-card p-1 shadow-rgPopover',
            'inset-e-0 ltr:origin-top-right rtl:origin-top-left' => $align !== 'start',
            'inset-s-0 ltr:origin-top-left rtl:origin-top-right' => $align === 'start',
        ])
        style="display: none;"
    >
        @foreach($enabled as $code => $info)
            <form method="POST" action="{{ route('locale.change') }}">
                @csrf
                <input type="hidden" name="locale" value="{{ $code }}">
                <button
                    type="submit"
                    data-testid="locale-option-{{ $code }}"
                    class="flex w-full cursor-pointer items-center gap-2 rounded-rgSm px-3 py-2 text-start text-sm transition hover:bg-rg-card2 {{ $code === $current ? 'font-semibold text-rg-text' : 'text-rg-text2' }}"
                >
                    <span aria-hidden="true" class="inline-block scale-125 leading-none" data-testid="locale-flag">{{ $info['flag'] }}</span>
                    {{ $info['native'] }}
                    @if($code === $current)
                        <svg class="ms-auto size-3 text-rg-accent" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                    @endif
                </button>
            </form>
        @endforeach
    </div>
</div>
@endif
