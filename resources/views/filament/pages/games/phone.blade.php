{{--
    An iPhone as the reference draws it — 402 × 820, radius 48, the island, the
    status bar and the home indicator — with the site's page in a frame below
    the 54px status bar. Drawn at --rg-admin-games-phone-scale (three quarters
    unless the screen sets it). The frame is $refs.frame, and its load calls
    the screen's apply(), which shows the page light or dark as `theme` says.

    $src, $title and $testid name the frame; $lazy loads it only once shown.
--}}
<div class="rg-admin-games__phone" x-bind:class="{ 'rg-admin-games__phone--dark': theme === 'dark' }">
    <div class="rg-admin-games__phone-device">
        <span class="rg-admin-games__phone-island" aria-hidden="true"></span>
        <div class="rg-admin-games__phone-status" aria-hidden="true">
            <span class="rg-admin-games__phone-time">9:41</span>
            <span class="rg-admin-games__phone-signals">
                <svg width="19" height="12" viewBox="0 0 19 12" fill="currentColor"><rect x="0" y="7.5" width="3.2" height="4.5" rx="0.7"/><rect x="4.8" y="5" width="3.2" height="7" rx="0.7"/><rect x="9.6" y="2.5" width="3.2" height="9.5" rx="0.7"/><rect x="14.4" y="0" width="3.2" height="12" rx="0.7"/></svg>
                <svg width="17" height="12" viewBox="0 0 17 12" fill="currentColor"><path d="M8.5 3.2C10.8 3.2 12.9 4.1 14.4 5.6L15.5 4.5C13.7 2.7 11.2 1.5 8.5 1.5C5.8 1.5 3.3 2.7 1.5 4.5L2.6 5.6C4.1 4.1 6.2 3.2 8.5 3.2Z"/><path d="M8.5 6.8C9.9 6.8 11.1 7.3 12 8.2L13.1 7.1C11.8 5.9 10.2 5.1 8.5 5.1C6.8 5.1 5.2 5.9 3.9 7.1L5 8.2C5.9 7.3 7.1 6.8 8.5 6.8Z"/><circle cx="8.5" cy="10.5" r="1.5"/></svg>
                <svg width="27" height="13" viewBox="0 0 27 13"><rect x="0.5" y="0.5" width="23" height="12" rx="3.5" stroke="currentColor" stroke-opacity="0.35" fill="none"/><rect x="2" y="2" width="20" height="9" rx="2" fill="currentColor"/><path d="M25 4.5V8.5C25.8 8.2 26.5 7.2 26.5 6.5C26.5 5.8 25.8 4.8 25 4.5Z" fill="currentColor" fill-opacity="0.4"/></svg>
            </span>
        </div>
        <iframe
            x-ref="frame"
            x-on:load="apply()"
            src="{{ $src }}"
            title="{{ $title }}"
            class="rg-admin-games__phone-screen"
            @if ($lazy ?? false) loading="lazy" @endif
            data-testid="{{ $testid }}"
        ></iframe>
        <span class="rg-admin-games__phone-home" aria-hidden="true"></span>
    </div>
</div>
