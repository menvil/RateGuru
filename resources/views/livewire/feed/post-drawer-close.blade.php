{{-- The panel's close button: on a post, in its author row beside Follow ($inline), and on a post that is gone,
     in the panel's corner, where it is the only way out on a phone. --}}
<button
    type="button"
    aria-label="{{ __('ui.a11y.close') }}"
    data-testid="post-detail-close"
    @if($asOverlay)
        x-on:click="$dispatch('request-close-overlay')"
    @else
        wire:click="$dispatch('clear-selected-post')"
    @endif
    class="{{ ($inline ?? false) ? '' : 'absolute inset-e-3.5 top-3.5' }} grid size-8 shrink-0 cursor-pointer place-items-center rounded-rgSm border border-rg-border2 bg-rg-card2 text-rg-text2 transition hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
>
    <x-ui.icon name="x" class="size-4" />
</button>
