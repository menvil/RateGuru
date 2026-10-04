@props([
    'action',
    'value' => null,
    'autoSubmit' => false,
    'showSubmit' => false,
    'focusWhenOpen' => false,
    'clearUrl' => null,
    'clearTestId' => null,
    'submitTestId' => null,
])

<form
    {{ $attributes->merge([
        'action' => $action,
        'method' => 'GET',
        'data-ui' => 'search-form',
    ]) }}
>
    <div @class(['relative', 'min-w-0 flex-1' => $showSubmit])>
        <x-ui.icon name="search" class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-rg-muted" />
        <input
            type="search"
            name="search"
            value="{{ $value }}"
            aria-label="{{ __('ui.feed.search_label') }}"
            placeholder="{{ __('ui.feed.search_placeholder') }}"
            @if($autoSubmit)
                x-on:input.debounce.450ms="if ($el.value.length === 0 || $el.value.length >= 3) $el.form.requestSubmit()"
                x-on:search="$el.form.requestSubmit()"
            @endif
            @if($focusWhenOpen)
                x-effect="if (mobileSearchOpen) setTimeout(() => $el.focus(), 60)"
            @endif
            class="rg-search-input h-10 w-full rounded-rgControl border border-rg-border bg-rg-card py-0 pl-10 pr-10 text-[13.5px] text-rg-text placeholder:text-rg-muted focus-visible:border-rg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent/25"
        >

        @if(filled($value) && filled($clearUrl))
            <a
                href="{{ $clearUrl }}"
                @if(filled($clearTestId)) data-testid="{{ $clearTestId }}" @endif
                aria-label="{{ __('ui.feed.clear_search') }}"
                title="{{ __('ui.feed.clear_search') }}"
                class="absolute right-2 top-1/2 grid size-7 -translate-y-1/2 place-items-center rounded-rgSm text-rg-muted transition hover:bg-rg-card2 hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
            >
                <x-ui.icon name="x" class="size-4" />
            </a>
        @endif
    </div>

    @if($showSubmit)
        <x-ui.button
            type="submit"
            size="lg"
            :data-testid="$submitTestId"
        >
            {{ __('ui.feed.search_button') }}
        </x-ui.button>
    @endif
</form>
