<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex h-[38px] cursor-pointer items-center justify-center gap-2 rounded-rgControl bg-rg-dangerSolid px-4 text-[13px] font-semibold text-white transition-colors hover:bg-rg-dangerSolid/85 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-danger focus-visible:ring-offset-2 focus-visible:ring-offset-rg-card active:bg-rg-dangerSolid/85 disabled:opacity-50']) }}>
    {{ $slot }}
</button>
