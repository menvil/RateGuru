@props([
    'title',
    'message',
])

<section
    role="alert"
    {{ $attributes->merge([
        'class' => 'w-full rounded-lg border border-red-400/20 bg-red-500/10 px-5 py-4 text-left shadow-[0_18px_60px_rgba(0,0,0,0.22)]',
    ]) }}
>
    <div class="flex gap-3">
        <div class="mt-1 size-2 shrink-0 rounded-full bg-red-300 shadow-[0_0_18px_rgba(252,165,165,0.45)]"></div>

        <div class="min-w-0 flex-1">
            <h2 class="text-sm font-semibold text-red-200">
                {{ $title }}
            </h2>

            <p class="mt-1 text-sm leading-6 text-red-100/80">
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
