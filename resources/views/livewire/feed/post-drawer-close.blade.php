{{-- The panel's close button: on a post, and on a post that is gone, where it is the only way out on a phone. --}}
<button
    type="button"
    aria-label="{{ __('ui.a11y.close') }}"
    data-testid="post-detail-close"
    @if($asOverlay)
        x-on:click="$dispatch('request-close-overlay')"
    @else
        wire:click="$dispatch('clear-selected-post')"
    @endif
    class="absolute right-3.5 top-3.5 grid size-8 cursor-pointer place-items-center rounded-rgSm border border-rg-border2 bg-rg-card2 text-rg-text2 transition hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
>
    <x-ui.icon name="x" class="size-4" />
</button>
