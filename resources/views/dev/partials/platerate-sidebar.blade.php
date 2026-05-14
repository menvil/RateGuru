<aside data-ui="platerate-sidebar" class="flex w-60 shrink-0 flex-col border-r border-rg-border bg-rg-sidebar px-4 py-5">
    @php
        $nav = [
            ['Home', 'home', true],
            ['Top', 'flame', false],
            ['New', 'plus', false],
            ['Following', 'users', false],
        ];
        $categories = ['All', 'Homemade', 'Restaurant', 'Desserts', 'Mains', 'Drinks', 'Breakfast'];
        $tags = ['#pasta', '#ramen', '#burger', '#brunch', '#dessert'];
    @endphp

    <nav class="space-y-1">
        @foreach ($nav as [$label, $icon, $active])
            <a
                href="#"
                class="{{ $active ? 'border-rg-border2 bg-rg-card2 text-rg-text' : 'border-transparent text-rg-text2 hover:bg-rg-card hover:text-rg-text' }} flex h-10 items-center gap-3 rounded-rgControl border px-3.5 text-[13.5px] font-semibold"
            >
                <x-ui.icon :name="$icon" class="size-4" />
                {{ $label }}
            </a>
        @endforeach
    </nav>

    <div class="mt-7">
        <p class="px-3 text-[11px] font-bold uppercase tracking-[0.12em] text-rg-muted">CATEGORIES</p>
        <div class="mt-3 space-y-1">
            @foreach ($categories as $category)
                <a href="#" class="block rounded-rgControl px-3.5 py-2 text-[13.5px] font-medium text-rg-text2 hover:bg-rg-card hover:text-rg-text">
                    {{ $category }}
                </a>
            @endforeach
        </div>
    </div>

    <div class="mt-7">
        <p class="px-3 text-[11px] font-bold uppercase tracking-[0.12em] text-rg-muted">TOP TAGS</p>
        <div class="mt-3 flex flex-wrap gap-2 px-3">
            @foreach ($tags as $tag)
                <span class="rounded-rgPill border border-rg-border bg-rg-card px-2.5 py-1 text-xs font-semibold text-rg-text2">{{ $tag }}</span>
            @endforeach
            <a href="#" class="w-full text-xs font-semibold text-rg-accent2">Show more</a>
        </div>
    </div>

    <div class="mt-auto flex flex-wrap gap-x-3 gap-y-2 px-3 pt-8 text-xs text-rg-muted">
        <a href="#">About</a>
        <a href="#">Terms</a>
        <a href="#">Privacy</a>
        <a href="#">Contact</a>
    </div>
</aside>
