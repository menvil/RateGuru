<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex h-[38px] cursor-pointer items-center justify-center gap-2 rounded-rgControl bg-rg-accent px-4 text-[13px] font-semibold text-rg-onAccent transition-colors hover:bg-rg-accentHover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent focus-visible:ring-offset-2 focus-visible:ring-offset-rg-card active:bg-rg-accentHover disabled:opacity-50']) }}>
    {{ $slot }}
</button>
