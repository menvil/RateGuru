<div
    {{ $attributes->merge(['class' => 'relative inline-block text-left']) }}
    x-data="{ open: false }"
    @click.outside="open = false"
    @keydown.escape.window="open = false"
>
    <div @click="open = ! open">
        {{ $trigger }}
    </div>

    <div
        x-cloak
        x-show="open"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        class="absolute right-0 z-50 mt-2 min-w-48 origin-top-right rounded-rgCard border border-rg-border bg-rg-card p-1 text-sm text-rg-text shadow-rgPopover ring-1 ring-rg-borderSoft"
        style="display: none;"
    >
        {{ $content }}
    </div>
</div>
