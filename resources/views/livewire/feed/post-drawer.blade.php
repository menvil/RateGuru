@inject('projectSettings', \App\Support\Settings\ProjectSettingsManager::class)
@if($asOverlay)
{{-- The <aside> is always mounted (not gated by @if($isOpen)) and its open/closed
     state is expressed purely as a class driven by $isOpen. This is deliberate: when
     an element is conditionally added/removed from the DOM via Livewire's morph, there
     is no prior painted state for the browser to transition FROM, so getting a slide-in
     to actually animate requires fighting the morph/paint timing by hand (a
     requestAnimationFrame-based version and an Alpine x-transition-based version were
     both tried here and both failed to reliably animate — the browser kept collapsing
     the "off-screen" and "in place" states into a single paint and skipping the
     transition, invisible at 200ms but obvious once the duration was turned up to
     debug it). Keeping the node permanently in the DOM sidesteps the problem entirely:
     Livewire's morph just updates the class ATTRIBUTE VALUE on an already-painted
     element, which is a completely ordinary style change and a CSS transition fires
     for it the same as it would for any other class swap — no JS timing tricks needed.
     `inert` plus pointer-events-none keep it non-interactive and untabbable while
     closed. --}}
<div
    data-testid="post-detail-overlay-host"
    x-data="{ returnFocusEl: null }"
    class="pointer-events-none fixed inset-x-0 top-[60px] bottom-0 z-50"
>
    <div class="relative mx-auto h-full w-full max-w-[1440px] overflow-hidden">
        <aside
            x-data
            {{-- Open is flipped client-side the moment the (browser-level) select-post
                 event fires, without waiting for the server round trip. When the open
                 class only arrived with the Livewire morph, the same rendering update
                 that revealed the panel also inserted the whole post content, and the
                 browser collapsed before/after into a single paint — the slide-IN never
                 animated (slide-OUT always worked, because closing is a class-only
                 morph). Flipping the class here, frames before the morph lands, gives
                 the transition its own clean start; the morph then re-renders the same
                 open-state classes as a no-op. --}}
            x-on:select-post.window="
                $el.classList.remove('translate-x-full', 'pointer-events-none', 'shadow-none');
                $el.classList.add('translate-x-0', 'pointer-events-auto', 'shadow-rgPopover');
                $el.removeAttribute('inert');
            "
            x-on:post-selected.window="
                document.documentElement.classList.add('overflow-hidden');
                returnFocusEl = document.activeElement;
                $nextTick(() => $el.focus());
                if (! $event.detail.focus) return;
                $nextTick(() => {
                    setTimeout(() => {
                        requestAnimationFrame(() => {
                            const selector = $event.detail.focus === 'comments' ? '[data-testid=\'drawer-comments-slot\']' : null;
                            const targetEl = selector ? $el.querySelector(selector) : null;
                            if (targetEl) {
                                $el.scrollTo({ top: targetEl.offsetTop - 12, behavior: 'smooth' });
                            }
                        });
                    }, 80);
                });
            "
            x-on:request-close-overlay.window="
                document.documentElement.classList.remove('overflow-hidden');
                $wire.closeOverlay();
                $dispatch('clear-selected-post');
                returnFocusEl?.focus();
            "
            {{-- Deletion closes via clear-selected-post directly (no request-close-overlay),
                 since the post is already gone server-side — this still shares the same
                 close styling/transition. Without this listener, deleting a post left the
                 scroll lock stuck, because request-close-overlay (the only other place that
                 removed 'overflow-hidden') never fired. --}}
            x-on:clear-selected-post.window="
                document.documentElement.classList.remove('overflow-hidden');
                returnFocusEl?.focus();
            "
            x-on:keydown.escape.window="$dispatch('request-close-overlay')"
            role="dialog"
            aria-modal="true"
            tabindex="-1"
            @if($post)
                aria-labelledby="post-drawer-title"
            @else
                aria-label="{{ __('ui.a11y.post_detail_dialog') }}"
            @endif
            @unless($isOpen) inert @endunless
            data-testid="post-detail-overlay"
            @class([
                {{-- transition-[translate,...]: Tailwind v4's translate-x-* utilities set the
                     native CSS `translate` property (not `transform`), so `translate` is what
                     must be listed in transition-property for the slide to animate. --}}
                'absolute right-0 top-0 bottom-0 w-full overflow-y-auto border-l border-rg-border bg-rg-card px-4 py-5 transition-[translate,box-shadow] duration-200 ease-out motion-reduce:transition-none sm:px-6 md:w-[min(70vw,1008px)] focus:outline-none',
                'pointer-events-auto translate-x-0 shadow-rgPopover' => $isOpen,
                {{-- shadow-none while closed: the panel parks with its left edge flush against
                     the site's right border, so a persistent shadow's blur would bleed left into
                     the visible page as a permanent smudge along the site edge. The shadow is
                     part of the transition so it fades in/out with the slide. --}}
                'pointer-events-none translate-x-full shadow-none' => ! $isOpen,
            ])
        >
            @include('livewire.feed.post-drawer-content')
        </aside>
    </div>
</div>
@else
    @include('livewire.feed.post-drawer-content')
@endif
