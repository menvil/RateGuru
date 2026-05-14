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

            <section class="rounded-lg border border-white/10 bg-zinc-900/60 p-5 md:col-span-2">
                <h2 class="text-base font-semibold text-white">Cards</h2>

                <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <x-ui.card>
                        <p class="text-sm font-semibold text-white">Default Card</p>
                        <p class="mt-2 text-sm text-zinc-400">
                            Dense dark surface for ordinary PlateRate content.
                        </p>
                    </x-ui.card>

                    <x-ui.card variant="elevated">
                        <p class="text-sm font-semibold text-white">Elevated Card</p>
                        <p class="mt-2 text-sm text-zinc-400">
                            Stronger depth for highlighted restaurants or picks.
                        </p>
                    </x-ui.card>

                    <x-ui.card variant="interactive">
                        <p class="text-sm font-semibold text-white">Interactive Card</p>
                        <p class="mt-2 text-sm text-zinc-400">
                            Hover-ready surface for selectable results.
                        </p>
                    </x-ui.card>

                    <x-ui.card padding="none" class="overflow-hidden">
                        <x-ui.image-placeholder label="Food Image Placeholder" ratio="video" />

                        <div class="p-4">
                            <p class="text-sm font-semibold text-white">Card With Image</p>
                            <p class="mt-2 text-sm text-zinc-400">
                                Placeholder keeps food media framed inside the card.
                            </p>
                        </div>
                    </x-ui.card>

                    <x-ui.card>
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-semibold text-white">Card With Badge</p>
                                <p class="mt-2 text-sm text-zinc-400">
                                    Badge metadata stays compact in the header.
                                </p>
                            </div>

                            <x-ui.badge variant="warning" size="sm">Popular</x-ui.badge>
                        </div>
                    </x-ui.card>
                </div>
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
