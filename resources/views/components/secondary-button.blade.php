<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex h-[38px] cursor-pointer items-center justify-center gap-2 rounded-rgControl border border-rg-border2 bg-rg-card px-4 text-[13px] font-semibold text-rg-text2 transition-colors hover:bg-rg-card2 hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent focus-visible:ring-offset-2 focus-visible:ring-offset-rg-card disabled:opacity-50']) }}>
    {{ $slot }}
</button>
