@php
    $options = [
        ['value' => 'system', 'label' => 'System', 'icon' => 'monitor'],
        ['value' => 'light',  'label' => 'Light',  'icon' => 'sun'],
        ['value' => 'dark',   'label' => 'Dark',   'icon' => 'moon'],
    ];
@endphp

@if($layout === 'dropdown')
    {{-- Full-width labeled buttons for inside the user dropdown --}}
    <div data-testid="theme-switcher" class="flex gap-1">
        @foreach($options as $option)
            <button
                type="button"
                wire:key="theme-option-{{ $option['value'] }}"
                wire:click="setThemePreference('{{ $option['value'] }}')"
                onclick="rgSetTheme('{{ $option['value'] }}')"
                data-testid="theme-option-{{ $option['value'] }}"
                title="{{ $option['label'] }}"
                aria-label="{{ $option['label'] }}"
                aria-pressed="{{ $preference === $option['value'] ? 'true' : 'false' }}"
                @class([
                    'flex flex-1 cursor-pointer items-center justify-center gap-1.5 rounded-rgSm py-1.5 text-xs font-medium transition-all',
                    'bg-rg-card2 text-rg-text shadow-sm ring-1 ring-rg-border' => $preference === $option['value'],
                    'text-rg-muted hover:bg-rg-card2 hover:text-rg-text' => $preference !== $option['value'],
                ])
            >
                <x-ui.icon name="{{ $option['icon'] }}" class="size-3.5" />
                <span>{{ $option['label'] }}</span>
            </button>
        @endforeach
    </div>
@else
    {{-- Compact icon-only switcher for the header (guest users) --}}
    <div
        data-testid="theme-switcher"
        class="flex items-center gap-0.5 rounded-rgControl border border-rg-border bg-rg-card p-0.5"
    >
        @foreach($options as $option)
            <button
                type="button"
                wire:key="theme-option-{{ $option['value'] }}"
                wire:click="setThemePreference('{{ $option['value'] }}')"
                onclick="rgSetTheme('{{ $option['value'] }}')"
                data-testid="theme-option-{{ $option['value'] }}"
                title="{{ $option['label'] }}"
                aria-label="{{ $option['label'] }}"
                aria-pressed="{{ $preference === $option['value'] ? 'true' : 'false' }}"
                @class([
                    'flex cursor-pointer items-center justify-center rounded-[7px] p-1.5 transition-all',
                    'bg-rg-surface text-rg-text shadow-sm' => $preference === $option['value'],
                    'text-rg-muted hover:text-rg-text' => $preference !== $option['value'],
                ])
            >
                <x-ui.icon name="{{ $option['icon'] }}" class="size-3.5" />
            </button>
        @endforeach
    </div>
@endif
