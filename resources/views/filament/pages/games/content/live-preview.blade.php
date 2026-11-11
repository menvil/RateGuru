{{--
    The round on screen, played in an iPhone's frame as a phone shows it: a
    402 × 820 device — its island, status bar and home indicator — drawn at
    three quarters, with the site's own game screen below the status bar,
    drawn from the draft as it stands, unsaved edits and chosen pictures
    included; the frame's address names the draft, so it draws again whenever
    the draft changes.
    Theme shows the site light or dark, as a visitor may have it; Photos frames
    every pair as its pictures are, or all wide, square (as a mixed pair is) or
    upright, to see how such pictures sit. Reset plays the round again from
    before the pick; Full game opens Preview. Docked beside the round from 1360
    up; below, it opens over the page from Preview round and closes again.
    Nothing the frame does is recorded.
--}}
<aside
    class="rg-admin-games__pane rg-admin-games__pane--live"
    x-bind:class="{ 'rg-admin-games__pane--open': preview }"
    aria-label="Live preview"
    x-data="{
        theme: null,
        apply() {
            const root = this.$refs.frame?.contentDocument?.documentElement

            if (! root) {
                return
            }

            this.theme ??= root.dataset.theme === 'dark' ? 'dark' : 'light'
            root.dataset.theme = this.theme
        },
    }"
    x-init="$watch('theme', () => apply())"
>
    <div class="rg-admin-games__pane-inner">
        <div class="rg-admin-games__live-head">
            <span class="rg-admin-games__live-title">Live preview</span>
            <x-admin.ui.button size="sm" icon="rotate-ccw" x-on:click="$refs.frame.src = $refs.frame.src">Reset</x-admin.ui.button>
            <x-admin.ui.button size="sm" icon="play" :href="$fullPreviewUrl">Full game</x-admin.ui.button>
            <x-admin.ui.icon-button class="rg-admin-games__live-close" icon="x" label="Close preview" variant="ghost" size="sm" x-on:click="preview = false" />
        </div>

        @include('filament.pages.games.phone', ['src' => $liveUrl, 'title' => 'Live preview of round '.($selected + 1), 'testid' => 'game-live-preview', 'lazy' => true])

        <div class="rg-admin-games__live-theme">
            <span class="rg-admin-games__note">Theme</span>
            <x-admin.ui.segmented label="Theme" :options="['dark' => 'Dark', 'light' => 'Light']" value="light" x-model="theme" compact />
        </div>
        <div class="rg-admin-games__live-theme">
            <span class="rg-admin-games__note">Photos</span>
            <x-admin.ui.segmented label="Photos" :options="['uploaded' => 'As uploaded', 'landscape' => 'Landscape', 'square' => 'Mixed', 'portrait' => 'Portrait']" :value="$previewShape" wire:model.live="previewShape" compact />
        </div>

        <p class="rg-admin-games__note rg-admin-games__live-note">The same screen as Full game on a phone, unsaved changes included — pictures chosen here too. Nothing is recorded.</p>
    </div>
</aside>
