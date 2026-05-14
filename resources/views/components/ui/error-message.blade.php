@props([
    'title',
    'message',
])

<section
    role="alert"
    {{ $attributes->merge([
        'class' => 'w-full rounded-rgCard border border-rg-dangerBorder bg-rg-dangerSoft px-5 py-4 text-left shadow-rgPopover',
    ]) }}
>
    <div class="flex gap-3">
        <div class="mt-1 size-2 shrink-0 rounded-full bg-rg-dangerText" style="box-shadow: 0 0 18px var(--rg-danger-glow)"></div>

        <div class="min-w-0 flex-1">
            <h2 class="text-sm font-semibold text-rg-dangerText">
                {{ $title }}
            </h2>

            <p class="mt-1 text-sm leading-6 text-rg-dangerMuted">
                {{ $message }}
            </p>

            @isset($action)
                <div class="mt-4">
                    {{ $action }}
                </div>
            @endisset
        </div>
    </div>
</section>
