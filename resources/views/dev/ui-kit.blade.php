@extends('layouts.app')

@section('content')
    <section class="space-y-8">
        <div class="max-w-3xl">
            <p class="text-sm font-medium uppercase tracking-normal text-amber-300">Development</p>
            <h1 class="mt-3 text-3xl font-semibold text-white sm:text-4xl">
                RateGuru UI Kit
            </h1>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <section class="rounded-lg border border-white/10 bg-zinc-900/60 p-5">
                <h2 class="text-base font-semibold text-white">Buttons</h2>

                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <x-ui.button variant="primary">Primary Button</x-ui.button>
                    <x-ui.button variant="secondary">Secondary Button</x-ui.button>
                    <x-ui.button variant="ghost">Ghost Button</x-ui.button>
                    <x-ui.button variant="danger">Danger Button</x-ui.button>
                    <x-ui.button disabled>Disabled Button</x-ui.button>
                </div>
            </section>

            <section class="rounded-lg border border-white/10 bg-zinc-900/60 p-5">
                <h2 class="text-base font-semibold text-white">Cards</h2>
            </section>

            <section class="rounded-lg border border-white/10 bg-zinc-900/60 p-5">
                <h2 class="text-base font-semibold text-white">Forms</h2>
            </section>

            <section class="rounded-lg border border-white/10 bg-zinc-900/60 p-5">
                <h2 class="text-base font-semibold text-white">Overlays</h2>
            </section>

            <section class="rounded-lg border border-white/10 bg-zinc-900/60 p-5">
                <h2 class="text-base font-semibold text-white">Feedback</h2>
            </section>

            <section class="rounded-lg border border-white/10 bg-zinc-900/60 p-5">
                <h2 class="text-base font-semibold text-white">Reference</h2>
            </section>
        </div>
    </section>
@endsection
