{{--
    The posts list in the top bar (NAV-02): when it was read, and Refresh,
    which asks the page to read it again for what was submitted or moderated
    since. The page answers with the time it read it.
--}}
<div class="rg-admin-topbar__meta-actions" x-data="{ at: @js($at) }" x-on:rg-admin-posts-refreshed.window="at = $event.detail.at">
    <span class="rg-admin-topbar__meta" x-text="`Updated ${at}`">Updated {{ $at }}</span>
    <x-admin.ui.button icon="refresh-cw" x-on:click="$dispatch('refresh-posts')">Refresh</x-admin.ui.button>
</div>
