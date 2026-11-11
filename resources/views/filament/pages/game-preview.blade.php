@php
    /*
     * A game's Preview (PreviewGame), as the reference draws it: the switches
     * in a band — what to play, light or dark, a phone or a desktop, a browser
     * window or the whole page, as whom and in which language — then the game
     * itself on the stage, the site's own page in a frame, and Journey beside
     * it: the flow's steps, the one on screen marked as the frame moves, each
     * a way to start again there. Restart plays it again from where it began;
     * Fullscreen lays the whole of it over the window. Nothing the frame does
     * is recorded.
     */
    $view = $device === 'mobile' ? 'mobile' : 'desktop';
    $display = $device === 'page' ? 'page' : 'window';
@endphp

<div
    class="rg-admin rg-admin-screen rg-admin-games"
    x-data="{
        fs: false,
        theme: null,
        step: null,
        view: @js($view),
        display: @js($display),
        scale: 1,
        restart() {
            this.$refs.frame.src = this.$refs.frame.src
        },
        apply() {
            const root = this.$refs.frame?.contentDocument?.documentElement

            if (! root) {
                return
            }

            this.theme ??= root.dataset.theme === 'dark' ? 'dark' : 'light'
            root.dataset.theme = this.theme

            // Journey marks the step on screen as the frame moves through the flow.
            const read = () => { this.step = root.querySelector('[data-testid=game-page]')?.dataset.step || null }
            read()
            this.observer?.disconnect()
            this.observer = new MutationObserver(read)
            this.observer.observe(root, { subtree: true, childList: true, attributes: true, attributeFilter: ['data-step'] })
        },
        fit() {
            const stage = this.$refs.stage

            if (! stage) {
                return
            }

            const scale = Math.max(0.2, Math.min(1, (stage.clientWidth - 48) / (this.view === 'mobile' ? 402 : 1000)))

            if (Math.abs(scale - this.scale) > 0.001) {
                this.scale = scale
            }
        },
    }"
    x-init="
        $watch('theme', () => apply())
        $watch('view', (value) => { fit(); $wire.set('device', value === 'mobile' ? 'mobile' : (display === 'page' ? 'page' : 'desktop')) })
        $watch('display', (value) => $wire.set('device', value === 'page' ? 'page' : 'desktop'))
        // Fitted on the next frame, so the frame's new size never feeds back into the same observation.
        new ResizeObserver(() => requestAnimationFrame(() => fit())).observe($refs.body)
    "
    x-on:keydown.escape.window="fs = false"
>
    @include('filament.pages.games.editor-header', ['header' => $header])

    <div class="rg-admin-games__preview" x-bind:class="{ 'rg-admin-games__preview--fs': fs }">
        <div class="rg-admin-games__preview-bar">
            <span class="rg-admin-games__preview-chip"><x-admin.ui.icon name="eye" :size="14" />Preview mode</span>

            <label class="rg-admin-games__preview-group">
                <span class="rg-admin-games__note">Source</span>
                <select class="rg-admin-games__select" wire:model.live="source">
                    <option value="draft">Draft</option>
                    @foreach ($versions as $version)
                        <option value="{{ $version['id'] }}">{{ $version['label'] }} as published</option>
                    @endforeach
                </select>
            </label>
            @if ($source === 'draft')
                <label class="rg-admin-games__preview-group">
                    <span class="rg-admin-games__note">Content set</span>
                    <select class="rg-admin-games__select" wire:model.live="set">
                        @foreach ($sets as $item)
                            <option value="{{ $item->id }}">{{ $item->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="rg-admin-games__preview-group">
                    <span class="rg-admin-games__note">Flow</span>
                    <select class="rg-admin-games__select" wire:model.live="flow">
                        @foreach ($flows as $item)
                            <option value="{{ $item->id }}">{{ $item->name }}</option>
                        @endforeach
                    </select>
                </label>
            @endif

            <div class="rg-admin-games__preview-group">
                <span class="rg-admin-games__note">Theme</span>
                <x-admin.ui.segmented label="Theme" :options="['dark' => 'Dark', 'light' => 'Light']" value="light" x-model="theme" compact />
            </div>
            <div class="rg-admin-games__preview-group">
                <span class="rg-admin-games__note">View</span>
                <x-admin.ui.segmented label="View" :options="['mobile' => 'Mobile', 'desktop' => 'Desktop']" :value="$view" x-model="view" compact />
            </div>
            @if ($view === 'desktop')
                <div class="rg-admin-games__preview-group">
                    <span class="rg-admin-games__note">Display</span>
                    <x-admin.ui.segmented label="Display" :options="['window' => 'Browser window', 'page' => 'Full page']" :value="$display" x-model="display" compact />
                </div>
            @endif
            <div class="rg-admin-games__preview-group">
                <span class="rg-admin-games__note">Visitor</span>
                <x-admin.ui.segmented label="Visitor" :options="['anonymous' => 'Anonymous', 'authenticated' => 'Logged in']" :value="$visitor" wire:model.live="visitor" compact />
            </div>
            <label class="rg-admin-games__preview-group">
                <span class="rg-admin-games__note">Language</span>
                <select class="rg-admin-games__select" wire:model.live="locale">
                    @foreach ($locales as $item)
                        <option value="{{ $item['code'] }}">{{ $item['label'] }}</option>
                    @endforeach
                </select>
            </label>

            <div class="rg-admin-games__preview-actions">
                <x-admin.ui.button size="sm" icon="rotate-ccw" x-on:click="restart()">Restart</x-admin.ui.button>
                <x-admin.ui.button size="sm" icon="external-link" :href="$frameUrl" target="_blank">Open</x-admin.ui.button>
                <x-admin.ui.button size="sm" variant="primary" icon="monitor" x-on:click="fs = ! fs"><span x-text="fs ? 'Exit fullscreen' : 'Fullscreen'">Fullscreen</span></x-admin.ui.button>
            </div>
        </div>

        <div class="rg-admin-games__preview-body" x-ref="body">
            <div @class(['rg-admin-games__preview-stage', 'rg-admin-games__preview-stage--page' => $device === 'page']) x-ref="stage" wire:key="stage-{{ $device }}">
                @if ($device === 'mobile')
                    <div x-bind:style="`--rg-admin-games-phone-scale: ${scale}`">
                        @include('filament.pages.games.phone', ['src' => $frameUrl, 'title' => 'Game preview', 'testid' => 'game-preview-frame'])
                    </div>
                @elseif ($device === 'desktop')
                    <div x-bind:style="`--rg-admin-games-browser-scale: ${scale}`">
                        @include('filament.pages.games.browser', ['src' => $frameUrl, 'title' => 'Game preview', 'testid' => 'game-preview-frame', 'address' => $address, 'tab' => $tabTitle])
                    </div>
                @else
                    <iframe x-ref="frame" x-on:load="apply()" src="{{ $frameUrl }}" title="Game preview" class="rg-admin-games__page-frame" data-testid="game-preview-frame"></iframe>
                @endif
            </div>

            <aside class="rg-admin-games__journey" aria-label="Journey">
                <div>
                    <h2 class="rg-admin-games__journey-title">Journey</h2>
                    <p class="rg-admin-games__note">The flow’s steps as this visitor meets them. Choose one to start there.</p>
                </div>
                <ol class="rg-admin-games__journey-list" x-data="{ keys: @js(array_column($journey, 'key')) }">
                    @foreach ($journey as $index => $item)
                        <li>
                            <button
                                type="button"
                                class="rg-admin-games__journey-step"
                                x-bind:class="{ 'rg-admin-games__journey-step--current': step === @js($item['key']), 'rg-admin-games__journey-step--done': step !== null && keys.indexOf(step) > {{ $index }} }"
                                x-bind:aria-current="step === @js($item['key']) ? 'step' : null"
                                x-on:click="$wire.start === @js('step:'.$item['key']) ? restart() : $wire.set('start', @js('step:'.$item['key']))"
                            >
                                <span class="rg-admin-games__journey-dot"><x-admin.ui.icon :name="$item['icon']" :size="12" /></span>
                                <span class="rg-admin-games__journey-text">
                                    <span class="rg-admin-games__journey-name">{{ $item['label'] }}</span>
                                    @if ($item['audience'] !== null)
                                        <span class="rg-admin-games__note">{{ $item['audience'] }} only</span>
                                    @endif
                                </span>
                            </button>
                        </li>
                    @endforeach
                </ol>
                <label class="rg-admin-games__journey-tools">
                    <span class="rg-admin-games__note">Jump to the result for</span>
                    <select class="rg-admin-games__select" x-on:change="$event.target.value !== '' && $wire.set('start', $event.target.value)">
                        <option value="" @selected(! str_starts_with($start, 'result:'))>Choose a score…</option>
                        @foreach ($results as $value => $label)
                            <option value="{{ $value }}" @selected($start === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <p class="rg-admin-games__note">Preview only. Picks, emails and accounts aren’t recorded.</p>
            </aside>
        </div>
    </div>
</div>
