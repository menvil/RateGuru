<div
    data-testid="theme-switcher"
    class="flex items-center gap-0.5 rounded-rgControl border border-rg-border bg-rg-card p-0.5"
>
    @foreach([
        ['value' => 'system', 'label' => 'System', 'icon' => 'monitor'],
        ['value' => 'light', 'label' => 'Light', 'icon' => 'sun'],
        ['value' => 'dark', 'label' => 'Dark', 'icon' => 'moon'],
    ] as $option)
        <button
            type="button"
            wire:key="theme-option-{{ $option['value'] }}"
            wire:click="setThemePreference('{{ $option['value'] }}')"
            x-on:click.prevent="
                var pref = '{{ $option['value'] }}';
                var applied = pref === 'system'
                    ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
                    : pref;
                document.documentElement.dataset.theme = applied;
                document.documentElement.dataset.themePreference = pref;
                try { localStorage.setItem('rateguru.theme.preference', pref); } catch(e) {}
            "
            data-testid="theme-option-{{ $option['value'] }}"
            title="{{ $option['label'] }}"
            aria-label="{{ $option['label'] }}"
            aria-pressed="{{ $preference === $option['value'] ? 'true' : 'false' }}"
            @class([
                'flex items-center justify-center rounded-[7px] p-1.5 transition-all',
                'bg-rg-surface text-rg-text shadow-sm' => $preference === $option['value'],
                'text-rg-muted hover:text-rg-text' => $preference !== $option['value'],
            ])
        >
            <x-ui.icon name="{{ $option['icon'] }}" class="size-3.5" />
        </button>
    @endforeach
</div>
