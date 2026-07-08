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
    x-data="{ returnFocusEl: null }"
    class="pointer-events-none fixed inset-x-0 top-[60px] bottom-0 z-50"
>
    <div class="relative mx-auto h-full w-full max-w-[1440px] overflow-hidden">
        @if($isOpen)
            <aside
                x-data
                x-init="
                    document.documentElement.classList.add('overflow-hidden');
                    @if($justOpened)
                        returnFocusEl = document.activeElement;
                        requestAnimationFrame(() => requestAnimationFrame(() => { $el.classList.remove('translate-x-full'); $el.focus(); }));
                    @endif
                "
                x-on:request-close-overlay.window="
                    $el.classList.add('translate-x-full');
                    document.documentElement.classList.remove('overflow-hidden');
                    setTimeout(() => { $wire.closeOverlay(); $dispatch('clear-selected-post'); returnFocusEl?.focus(); }, 200)
                "
                {{-- Deletion closes via clear-selected-post directly (no request-close-overlay),
                     since the post is already gone server-side and there is nothing left to
                     slide out over — so this cleans up immediately instead of duplicating the
                     200ms slide-out delay above. Without this, deleting a post left the scroll
                     lock and backdrop stuck after the <aside> itself was removed from the DOM
                     by @if($isOpen), because request-close-overlay (the only other place that
                     removed 'overflow-hidden') never fired. --}}
                x-on:clear-selected-post.window="
                    document.documentElement.classList.remove('overflow-hidden');
                    returnFocusEl?.focus();
                "
                x-on:post-selected.window="
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
                x-on:keydown.escape.window="$dispatch('request-close-overlay')"
                role="dialog"
                aria-modal="true"
                tabindex="-1"
                @if($post)
                    aria-labelledby="post-drawer-title"
                @else
                    aria-label="{{ __('ui.a11y.post_detail_dialog') }}"
                @endif
                data-testid="post-detail-overlay"
                @class([
                    'pointer-events-auto absolute right-0 top-0 bottom-0 w-full overflow-y-auto border-l border-rg-border bg-rg-card px-4 py-5 shadow-rgPopover transition-transform duration-200 ease-out motion-reduce:transition-none sm:px-6 md:w-[min(70vw,1008px)] focus:outline-none',
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
