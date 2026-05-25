@php
    use App\Models\Tag;

    $navItems = [
        ['label' => 'Home', 'icon' => 'home', 'href' => route('feed'), 'active' => request()->routeIs('feed') && blank(request('sort')) && blank(request('category')) && blank(request('search'))],
        ['label' => 'Top', 'icon' => 'flame', 'href' => route('feed', ['sort' => 'top']), 'active' => request('sort') === 'top'],
        ['label' => 'New', 'icon' => 'plus', 'href' => route('feed', ['sort' => 'newest']), 'active' => request('sort') === 'newest'],
        ['label' => 'Following', 'icon' => 'users', 'href' => '#', 'active' => false],
    ];

    $categories = ['All', 'Homemade', 'Restaurant', 'Desserts', 'Mains', 'Drinks', 'Breakfast'];
    $topTags = Tag::query()->orderBy('name')->limit(5)->get();
    $fallbackTags = ['pasta', 'ramen', 'burger', 'brunch', 'dessert'];
@endphp

<aside data-testid="app-sidebar" class="hidden w-60 shrink-0 flex-col border-r border-rg-border bg-rg-sidebar px-4 py-5 lg:sticky lg:top-[60px] lg:flex lg:h-[calc(100vh-60px)]">
    <nav class="space-y-1" aria-label="Primary">
        @foreach ($navItems as $item)
            <a
                href="{{ $item['href'] }}"
                @if ($item['href'] === '#') aria-disabled="true" @endif
                class="{{ $item['active'] ? 'border-rg-border2 bg-rg-card2 text-rg-text' : 'border-transparent text-rg-text2 hover:bg-rg-card hover:text-rg-text' }} flex h-10 items-center gap-3 rounded-rgControl border px-3.5 text-[13.5px] font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent {{ $item['href'] === '#' ? 'cursor-not-allowed opacity-70' : '' }}"
            >
                <x-ui.icon :name="$item['icon']" class="size-4" />
                {{ $item['label'] }}
            </a>
        @endforeach
    </nav>

    <div class="mt-7">
        <p class="px-3 text-[11px] font-bold uppercase tracking-[0.12em] text-rg-muted">CATEGORIES</p>
        <div class="mt-3 space-y-1">
            @foreach ($categories as $category)
                @php
                    $categorySlug = str($category)->lower()->slug()->toString();
                    $href = $category === 'All' ? route('feed') : route('feed', ['category' => $categorySlug]);
                    $isActive = $category === 'All'
                        ? blank(request('category'))
                        : request('category') === $categorySlug;
                @endphp

                <a
                    href="{{ $href }}"
                    class="{{ $isActive ? 'bg-rg-card2 text-rg-text' : 'text-rg-text2 hover:bg-rg-card hover:text-rg-text' }} block rounded-rgControl px-3.5 py-2 text-[13.5px] font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
                >
                    {{ $category }}
                </a>
            @endforeach
        </div>
    </div>

    <div class="mt-7">
        <p class="px-3 text-[11px] font-bold uppercase tracking-[0.12em] text-rg-muted">TOP TAGS</p>
        <div class="mt-3 flex flex-wrap gap-2 px-3">
            @forelse ($topTags as $tag)
                <a href="{{ route('feed', ['category' => $tag->slug]) }}" class="rounded-rgPill border border-rg-border bg-rg-card px-2.5 py-1 text-xs font-semibold text-rg-text2 transition hover:border-rg-accent hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent">
                    #{{ $tag->slug }}
                </a>
            @empty
                @foreach ($fallbackTags as $tag)
                    <a href="{{ route('feed', ['search' => $tag]) }}" class="rounded-rgPill border border-rg-border bg-rg-card px-2.5 py-1 text-xs font-semibold text-rg-text2 transition hover:border-rg-accent hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent">
                        #{{ $tag }}
                    </a>
                @endforeach
            @endforelse
        </div>
    </div>

    <div class="mt-auto flex flex-wrap gap-x-3 gap-y-2 px-3 pt-8 text-xs text-rg-muted">
        <a href="#" aria-disabled="true" class="cursor-not-allowed hover:text-rg-text">About</a>
        <a href="#" aria-disabled="true" class="cursor-not-allowed hover:text-rg-text">Terms</a>
        <a href="#" aria-disabled="true" class="cursor-not-allowed hover:text-rg-text">Privacy</a>
        <a href="#" aria-disabled="true" class="cursor-not-allowed hover:text-rg-text">Contact</a>
    </div>
</aside>
