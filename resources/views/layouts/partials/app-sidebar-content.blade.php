<nav class="space-y-1" aria-label="{{ __('ui.a11y.primary_nav') }}">
    @foreach ($navItems as $item)
        @if ($item['href'] === '#')
            <span
                aria-disabled="true"
                class="flex h-10 cursor-not-allowed items-center gap-3 rounded-rgControl border border-transparent px-3.5 text-[13.5px] font-semibold text-rg-text2 opacity-70"
            >
                <x-ui.icon :name="$item['icon']" class="size-4" />
                {{ $item['label'] }}
            </span>
        @else
            <a
                href="{{ $item['href'] }}"
                @if($item['testid']) data-testid="{{ $item['testid'] }}" @endif
                class="{{ $item['active'] ? 'border-rg-border2 bg-rg-card2 text-rg-text' : 'border-transparent text-rg-text2 hover:bg-rg-card hover:text-rg-text' }} flex h-10 items-center gap-3 rounded-rgControl border px-3.5 text-[13.5px] font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
            >
                <x-ui.icon :name="$item['icon']" class="size-4" />
                {{ $item['label'] }}
            </a>
        @endif
    @endforeach
</nav>

<div class="mt-7">
    <p class="px-3 text-[11px] font-bold uppercase tracking-[0.12em] text-rg-muted">{{ __('ui.feed.categories_heading') }}</p>
    <div class="mt-3 space-y-1">
        @foreach ($categories as $category)
            <a
                href="{{ $category['href'] }}"
                class="{{ $category['active'] ? 'bg-rg-card2 text-rg-text' : 'text-rg-text2 hover:bg-rg-card hover:text-rg-text' }} block rounded-rgControl px-3.5 py-2 text-[13.5px] font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
            >
                {{ $category['label'] }}
            </a>
        @endforeach
    </div>
</div>

<div class="mt-7">
    <p class="px-3 text-[11px] font-bold uppercase tracking-[0.12em] text-rg-muted">{{ __('ui.nav.top_tags_heading') }}</p>
    <div class="mt-3 flex flex-wrap gap-2 px-3">
        @forelse ($topTags as $tag)
            <a href="{{ $tag['href'] }}" class="rounded-rgPill border border-rg-border bg-rg-card px-2.5 py-1 text-xs font-semibold text-rg-text2 transition hover:border-rg-accent hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent">
                {{ $tag['label'] }}
            </a>
        @empty
            @foreach ($fallbackTags as $tag)
                <a href="{{ $tag['href'] }}" class="rounded-rgPill border border-rg-border bg-rg-card px-2.5 py-1 text-xs font-semibold text-rg-text2 transition hover:border-rg-accent hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent">
                    {{ $tag['label'] }}
                </a>
            @endforeach
        @endforelse
    </div>
</div>

<div class="mt-auto border-t border-rg-border pt-4 flex flex-wrap gap-x-3 gap-y-2 px-3 text-xs text-rg-muted">
    <a href="#" aria-disabled="true" class="cursor-not-allowed hover:text-rg-text">{{ __('ui.nav.about') }}</a>
    <a href="#" aria-disabled="true" class="cursor-not-allowed hover:text-rg-text">{{ __('ui.nav.terms') }}</a>
    <a href="#" aria-disabled="true" class="cursor-not-allowed hover:text-rg-text">{{ __('ui.nav.privacy') }}</a>
    <a href="#" aria-disabled="true" class="cursor-not-allowed hover:text-rg-text">{{ __('ui.nav.contact') }}</a>
</div>
