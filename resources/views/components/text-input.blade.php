@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'w-full rounded-rgControl border border-rg-border2 bg-rg-card2 px-3 py-2 text-sm text-rg-text placeholder-rg-muted shadow-sm transition-colors focus:border-rg-accent focus:outline-none focus:ring-1 focus:ring-rg-accent disabled:opacity-50']) }}>
