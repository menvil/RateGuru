@props(['icon'])

<button
    type="button"
    {{ $attributes->class([
        'inline-flex cursor-pointer items-center gap-1.5 bg-transparent p-0 text-[13px] font-medium text-rg-text2 transition hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent disabled:cursor-not-allowed disabled:opacity-60',
    ]) }}
>
    <x-ui.icon :name="$icon" class="size-4" />
    @if (! $slot->isEmpty())
        <span>{{ $slot }}</span>
    @endif
</button>
