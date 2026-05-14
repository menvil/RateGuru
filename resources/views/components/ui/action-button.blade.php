@props(['icon'])

<button
    type="button"
    {{ $attributes->class([
        'inline-flex items-center gap-1.5 bg-transparent p-0 text-[13px] font-medium text-rg-text2 transition hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent',
    ]) }}
>
    <x-ui.icon :name="$icon" class="size-4" />
    @if (trim($slot) !== '')
        <span>{{ $slot }}</span>
    @endif
</button>
