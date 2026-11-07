{{--
    Media diagnostics in the top bar (NAV-02): when the page was read;
    Refresh, which asks it to read everything again; and Run full audit,
    which dispatches an audit unless one is already running. The page answers
    Refresh with the time it read it. On a phone the time goes and Refresh
    keeps only its icon, its name still heard.
--}}
<div class="rg-admin-topbar__meta-actions rg-admin-media__topbar" x-data="{ at: @js($at) }" x-on:rg-admin-media-refreshed.window="at = $event.detail.at">
    <span class="rg-admin-topbar__meta" x-text="`Updated ${at}`">Updated {{ $at }}</span>
    <x-admin.ui.button icon="refresh-cw" x-on:click="$dispatch('refresh-media-diagnostics')"><span class="rg-admin-media__topbar-label">Refresh</span></x-admin.ui.button>
    <x-admin.ui.button variant="primary" icon="play" x-on:click="$dispatch('run-media-audit')">Run full audit</x-admin.ui.button>
</div>
