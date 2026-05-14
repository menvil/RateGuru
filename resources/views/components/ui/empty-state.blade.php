@props([
    'title',
    'description',
])

<section
    {{ $attributes->merge([
        'class' => 'flex w-full flex-col items-center justify-center rounded-lg border border-white/10 bg-zinc-950/80 px-6 py-10 text-center shadow-[0_18px_60px_rgba(0,0,0,0.28)]',
    ]) }}
>
    <div class="flex size-12 items-center justify-center rounded-full border border-purple-300/20 bg-zinc-900 shadow-[0_0_28px_rgba(168,85,247,0.16)]">
        <span class="size-2 rounded-full bg-amber-300 shadow-[0_0_18px_rgba(252,211,77,0.65)]"></span>
    </div>

    <div class="mt-5 max-w-md">
        <h2 class="text-base font-semibold text-zinc-50">
            {{ $title }}
        </h2>

        <p class="mt-2 text-sm leading-6 text-zinc-400">
            {{ $description }}
        </p>
    </div>

    @isset($action)
        <div class="mt-6">
            {{ $action }}
        </div>
    @endisset
</section>
