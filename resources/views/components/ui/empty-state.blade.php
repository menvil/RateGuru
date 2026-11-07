@props([
    'title',
    'description' => null,
    'message' => null,
])

@php
    $body = $description ?? $message ?? '';
@endphp

<section
    {{ $attributes->merge([
        'class' => 'flex w-full flex-col items-center justify-center rounded-rgCard border border-rg-border bg-rg-card px-6 py-10 text-center',
    ]) }}
>
    <div class="flex size-12 items-center justify-center rounded-full border border-rg-accentBorder bg-rg-accentSoft shadow-[0_0_28px_rgba(168,85,247,0.16)]">
        <span class="size-2 rounded-full bg-rg-accent2 shadow-[0_0_18px_rgba(192,132,252,0.65)]"></span>
    </div>

    <div class="mt-5 max-w-md">
        <h2 class="text-base font-semibold text-rg-text">
            {{ $title }}
        </h2>

        <p class="mt-2 text-sm leading-6 text-rg-muted">
            {{ $body }}
        </p>
    </div>

    @isset($action)
        <div class="mt-6">
            {{ $action }}
        </div>
    @endisset
</section>
