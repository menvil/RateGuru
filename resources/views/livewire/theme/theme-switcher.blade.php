<div
    data-testid="theme-switcher"
    x-data
    x-on:theme-preference-changed.window="
        var pref = $event.detail.preference;
        var applied = pref === 'system'
            ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
            : pref;
        document.documentElement.dataset.theme = applied;
        document.documentElement.dataset.themePreference = pref;
        try { localStorage.setItem('rateguru.theme.preference', pref); } catch(e) {}
    "
    class="flex items-center gap-1 rounded-rgControl border border-rg-border bg-rg-card p-0.5"
>
    @foreach([
        ['value' => 'system', 'label' => 'System', 'icon' => 'monitor'],
        ['value' => 'light', 'label' => 'Light', 'icon' => 'sun'],
        ['value' => 'dark', 'label' => 'Dark', 'icon' => 'moon'],
    ] as $option)
        <button
            type="button"
            wire:click="setThemePreference('{{ $option['value'] }}')"
            data-testid="theme-option-{{ $option['value'] }}"
            title="{{ $option['label'] }}"
            aria-pressed="{{ $preference === $option['value'] ? 'true' : 'false' }}"
            @class([
                'flex items-center gap-1.5 rounded-[7px] px-2.5 py-1.5 text-xs font-medium transition-all',
                'bg-rg-surface text-rg-text shadow-sm' => $preference === $option['value'],
                'text-rg-muted hover:text-rg-text' => $preference !== $option['value'],
            ])
        >
            <x-ui.icon name="{{ $option['icon'] }}" class="size-3.5" />
            <span class="hidden sm:inline">{{ $option['label'] }}</span>
        </button>
    @endforeach
</div>
