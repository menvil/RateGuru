@props([
    'title',
    'message',
])

<section
    role="alert"
    {{ $attributes->merge([
        'class' => 'w-full rounded-rgCard border border-[rgba(239,68,68,0.45)] bg-[rgba(239,68,68,0.12)] px-5 py-4 text-left shadow-rgPopover',
    ]) }}
>
    <div class="flex gap-3">
        <div class="mt-1 size-2 shrink-0 rounded-full bg-[#fca5a5] shadow-[0_0_18px_rgba(252,165,165,0.45)]"></div>

        <div class="min-w-0 flex-1">
            <h2 class="text-sm font-semibold text-[#fca5a5]">
                {{ $title }}
            </h2>

            <p class="mt-1 text-sm leading-6 text-[rgba(254,202,202,0.82)]">
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
