<header data-ui="platerate-topbar" class="flex h-[60px] items-center gap-5 border-b border-rg-border bg-rg-topbar px-5">
    <div class="shrink-0 text-[22px] font-extrabold tracking-normal text-rg-text">
        Plate<span class="text-rg-accent2">Rate</span>
    </div>

    <div class="relative max-w-[520px] flex-1">
        <x-ui.icon name="search" class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-rg-muted" />
        <input
            type="search"
            aria-label="Search tags, users, dishes"
            placeholder="Search tags, users, dishes..."
            class="h-10 w-full rounded-rgControl border border-rg-border bg-rg-card py-0 pl-10 pr-3 text-[13.5px] text-rg-text placeholder:text-rg-muted focus-visible:border-rg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent/25"
        >
    </div>

    <div class="ml-auto flex items-center gap-3">
        <x-ui.button elevated>
            <x-ui.icon name="upload" class="size-4" />
            Upload
        </x-ui.button>

        <button type="button" class="grid size-9 place-items-center rounded-rgControl border border-rg-border bg-rg-card text-rg-text2 transition hover:bg-rg-card2 hover:text-rg-text">
            <x-ui.icon name="bell" class="size-4" />
        </button>

        <x-ui.avatar name="pasta_lover" color="purple" size="lg" />
    </div>
</header>
