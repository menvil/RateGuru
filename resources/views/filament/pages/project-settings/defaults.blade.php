{{--
    The defaults a visitor gets until they choose (FRM-08): the theme and the
    feed's order, each saying what it means, “Differs from preset” while it
    does.
--}}
@php
    $choices = [
        'default_theme' => [
            'label' => 'Default theme',
            'help' => 'Used until a visitor picks their own.',
            'options' => ['system' => 'System', 'light' => 'Light', 'dark' => 'Dark'],
            'icons' => ['system' => 'monitor', 'light' => 'sun', 'dark' => 'moon'],
            'meanings' => ['system' => 'Follows the visitor’s device setting.', 'light' => 'Light until the visitor picks another theme.', 'dark' => 'Dark until the visitor picks another theme.'],
        ],
        'default_sort' => [
            'label' => 'Default sort',
            'help' => 'The order of the main feed for new visitors.',
            'options' => ['hot' => 'Hot', 'new' => 'New', 'top' => 'Top'],
            'icons' => ['hot' => 'flame', 'new' => 'clock', 'top' => 'trophy'],
            'meanings' => ['hot' => 'Recent posts with fast-growing votes first.', 'new' => 'Newest posts first.', 'top' => 'Highest rated posts first.'],
        ],
    ];
@endphp

<x-admin.ui.card flush>
    @foreach ($choices as $field => $choice)
        <div class="rg-admin-settings__choice">
            <div>
                <div class="rg-admin-switch-row__head">
                    <span class="rg-admin-switch-row__label" id="rg-admin-settings-{{ $field }}-label">{{ $choice['label'] }}</span>
                    <span class="rg-admin-settings__differs" x-cloak x-show="differs('{{ $field }}')">Differs from preset · <span x-text="shown('{{ $field }}', preset['{{ $field }}'])"></span></span>
                </div>
                <p class="rg-admin-switch-row__hint">{{ $choice['help'] }}</p>
            </div>
            <div class="rg-admin-settings__choice-control">
                <x-admin.ui.segmented
                    :label="$choice['label']"
                    :options="$choice['options']"
                    :icons="$choice['icons']"
                    :value="$data[$field]"
                    wire:model="data.{{ $field }}"
                />
                <span class="rg-admin-settings__meaning" x-text="@js($choice['meanings'])[$wire.data.{{ $field }}] ?? ''">{{ $choice['meanings'][$data[$field]] ?? '' }}</span>
                @error('data.'.$field)
                    <p class="rg-admin-error"><x-admin.ui.icon name="circle-alert" :size="12" />Choose one of the options.</p>
                @enderror
            </div>
        </div>
    @endforeach
</x-admin.ui.card>
