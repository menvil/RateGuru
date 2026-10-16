{{--
    The installation preset, read-only: presets are applied once from the
    server. The button names it and says whether the settings it controls
    still match it; its panel lists each that differs, with Revert to put
    the preset's value back in the draft. Nothing here applies a preset.
--}}
<div
    class="rg-admin-settings__preset"
    x-data="{ open: false }"
    x-on:click.outside="open = false"
    x-on:keydown.escape.stop="open = false; $refs.trigger.focus()"
>
    <button
        type="button"
        class="rg-admin-settings__preset-trigger"
        aria-expanded="false"
        aria-controls="rg-admin-settings-preset"
        x-ref="trigger"
        x-bind:aria-expanded="open ? 'true' : 'false'"
        x-on:click="open = ! open"
    >
        <span class="rg-admin-settings__preset-text">
            <span class="rg-admin-overline">{{ __('admin.project_settings.preset_status_title') }}</span>
            <span class="rg-admin-settings__preset-name">{{ $preset['label'] ?? 'None' }}</span>
        </span>
        @if (! $preset['applied'])
            <x-admin.ui.badge tone="neutral">Not applied</x-admin.ui.badge>
        @else
            <span x-show="differences.length === 0"><x-admin.ui.badge tone="success" :dot="true">Matches preset</x-admin.ui.badge></span>
            <span x-cloak x-show="differences.length > 0"><x-admin.ui.badge tone="warning"><span x-text="`Customized · ${differences.length}`"></span></x-admin.ui.badge></span>
        @endif
        <x-admin.ui.icon name="chevron-down" :size="16" />
    </button>

    <div id="rg-admin-settings-preset" class="rg-admin-settings__preset-panel" x-cloak x-show="open">
        <div class="rg-admin-settings__preset-head">
            <p class="rg-admin-settings__preset-title">{{ $preset['status'] }}</p>
            <p class="rg-admin-hint">{{ __('admin.project_settings.preset_status_description') }}</p>
        </div>

        @if ($preset['applied'])
            <ul class="rg-admin-settings__preset-list">
                <template x-for="path in differences" x-bind:key="path">
                    <li class="rg-admin-settings__preset-item">
                        <span class="rg-admin-settings__preset-item-text">
                            <span class="rg-admin-settings__preset-item-label" x-text="presetLabels[path]"></span>
                            <span class="rg-admin-hint">Preset <span x-text="shown(path, preset[path])"></span> → now <span x-text="shown(path, valueAt(path))"></span></span>
                        </span>
                        <x-admin.ui.button size="sm" x-on:click="revert(path)">Revert</x-admin.ui.button>
                    </li>
                </template>
            </ul>
            <p class="rg-admin-settings__preset-match" x-show="differences.length === 0">Every setting the preset controls matches it.</p>
        @endif
    </div>
</div>
