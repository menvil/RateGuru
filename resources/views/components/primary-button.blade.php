<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center rounded-rgControl bg-rg-accent px-4 py-2 text-xs font-semibold uppercase tracking-widest text-rg-onAccent transition-colors hover:bg-rg-accentHover focus:outline-none focus:ring-2 focus:ring-rg-accent focus:ring-offset-2 focus:ring-offset-rg-card active:bg-rg-accentHover']) }}>
    {{ $slot }}
</button>
