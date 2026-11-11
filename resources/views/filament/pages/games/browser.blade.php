{{--
    A browser window as the reference draws it — a dark Chrome on a Mac, 1000
    × 660: the traffic lights and the game's tab, the address bar with the
    game's address — with the site's page in a frame below. Drawn at
    --rg-admin-games-browser-scale. The frame is $refs.frame, and its load
    calls the screen's apply().

    $src, $title, $testid, $address and $tab name the frame and the window.
--}}
<div class="rg-admin-games__browser">
    <div class="rg-admin-games__browser-window">
        <div class="rg-admin-games__browser-tabs" aria-hidden="true">
            <span class="rg-admin-games__browser-lights"><span></span><span></span><span></span></span>
            <span class="rg-admin-games__browser-tab"><span class="rg-admin-games__browser-favicon"></span><span class="rg-admin-games__browser-tab-title">{{ $tab }}</span></span>
        </div>
        <div class="rg-admin-games__browser-toolbar" aria-hidden="true">
            <span class="rg-admin-games__browser-dot"></span>
            <span class="rg-admin-games__browser-address"><span class="rg-admin-games__browser-lock"></span>{{ $address }}</span>
            <span class="rg-admin-games__browser-dot"></span>
        </div>
        <iframe
            x-ref="frame"
            x-on:load="apply()"
            src="{{ $src }}"
            title="{{ $title }}"
            class="rg-admin-games__browser-screen"
            data-testid="{{ $testid }}"
        ></iframe>
    </div>
</div>
