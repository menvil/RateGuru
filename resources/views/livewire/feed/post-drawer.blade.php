@inject('projectSettings', \App\Support\Settings\ProjectSettingsManager::class)
@if($asOverlay)
{{-- Visibility is Blade-driven (@if($isOpen)), not Alpine's x-show/x-if, because the
     panel's CONTENT must keep updating via Livewire's normal morph while it stays open
     (e.g. switching to a different post). <template x-if> was tried first and looked
     right, but Alpine's morph integration deliberately shields x-if/x-show-controlled
     subtrees from Livewire's own re-renders once cloned, so the content silently froze
     after the very first post. Blade's own @if keeps the content live; the slide
     animation is instead done by hand (see $justOpened on the component) since neither
     x-show nor a bare x-transition reliably animates an element that Livewire itself
     inserts via morph.

     The <aside> is `absolute` inside an `overflow-hidden` wrapper bounded to the site's
     own max-w-[1440px] area — not `fixed` directly against the viewport. With `fixed`,
     the panel rendered as a fully opaque block sliding freely across the *entire* screen
     (including the undimmed margin outside the site), so during the animation it was
     plainly visible floating past the site's edge instead of being masked by it. Clipping
     it to this bounded, positioned ancestor means anything sliding past the site's edge
     is actually invisible, not just positioned off in the margin. --}}
<div
    data-testid="post-detail-overlay-host"
    class="pointer-events-none fixed inset-x-0 top-[60px] bottom-0 z-50"
>
    <div class="relative mx-auto h-full w-full max-w-[1440px] overflow-hidden">
        @if($isOpen)
            <aside
                x-data
                x-init="
                    document.documentElement.classList.add('overflow-hidden');
                    @if($justOpened)
                        requestAnimationFrame(() => requestAnimationFrame(() => $el.classList.remove('translate-x-full')));
                    @endif
                "
                x-on:request-close-overlay.window="
                    $el.classList.add('translate-x-full');
                    document.documentElement.classList.remove('overflow-hidden');
                    setTimeout(() => { $wire.closeOverlay(); $dispatch('clear-selected-post'); }, 200)
                "
                x-on:keydown.escape.window="$dispatch('request-close-overlay')"
                role="dialog"
                aria-modal="true"
                data-testid="post-detail-overlay"
                @class([
                    'pointer-events-auto absolute right-0 top-0 bottom-0 w-full overflow-y-auto border-l border-rg-border bg-rg-card px-4 py-5 shadow-rgPopover transition-transform duration-200 ease-out motion-reduce:transition-none sm:px-6 md:w-[min(70vw,1008px)]',
                    'translate-x-full' => $justOpened,
                ])
            >
                @include('livewire.feed.post-drawer-content')
            </aside>
        @endif
    </div>
</div>
@else
    @include('livewire.feed.post-drawer-content')
@endif
