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

                <div class="mt-4 space-y-4">
                    <div class="space-y-2">
                        <label for="ui-kit-form-dish-title" class="text-sm font-medium text-zinc-100">Dish title</label>
                        <x-ui.input
                            id="ui-kit-form-dish-title"
                            name="form_dish_title"
                            placeholder="Truffle mushroom toast"
                        />
                    </div>

                    <div class="space-y-2">
                        <label for="ui-kit-disabled-input" class="text-sm font-medium text-zinc-100">Disabled input</label>
                        <x-ui.input
                            id="ui-kit-disabled-input"
                            name="disabled_input"
                            value="Locked preview value"
                            disabled
                        />
                    </div>

                    <div class="space-y-2">
                        <label for="ui-kit-error-input" class="text-sm font-medium text-zinc-100">Input with error</label>
                        <x-ui.input
                            id="ui-kit-error-input"
                            name="error_input"
                            value="Missing rating context"
                            error
                        />
                    </div>

                    <div class="space-y-2">
                        <label for="ui-kit-form-description" class="text-sm font-medium text-zinc-100">Description</label>
                        <x-ui.textarea
                            id="ui-kit-form-description"
                            name="form_description"
                            rows="4"
                            placeholder="Describe taste, texture, and plating."
                        />
                    </div>

                    <x-ui.error-message
                        title="Validation error example"
                        message="Dish title must be specific enough for voters to understand the plate."
                    />
                </div>
            </section>

            <section class="rounded-lg border border-white/10 bg-zinc-900/60 p-5">
                <h2 class="text-base font-semibold text-white">Overlays</h2>

                <div class="mt-4 flex flex-wrap items-center gap-3" x-data="{ open: false }">
                    <x-ui.button x-on:click="open = true">Open Modal</x-ui.button>

                    <x-ui.modal title="Upload Dish Preview" size="lg">
                        <div class="space-y-4">
                            <div class="rounded-lg border border-dashed border-purple-400/30 bg-zinc-900/70 p-4 text-center">
                                <p class="text-sm font-semibold text-white">Dish photo preview</p>
                                <p class="mt-1 text-xs text-zinc-400">Dark upload surface for checking dish details before publishing.</p>
                            </div>

                            <div class="space-y-2">
                                <label for="ui-kit-dish-title" class="text-sm font-medium text-zinc-100">Dish title</label>
                                <x-ui.input
                                    id="ui-kit-dish-title"
                                    name="dish_title"
                                    placeholder="Smoked salmon tartine"
                                />
                            </div>

                            <div class="space-y-2">
                                <label for="ui-kit-dish-description" class="text-sm font-medium text-zinc-100">Description</label>
                                <x-ui.textarea
                                    id="ui-kit-dish-description"
                                    name="description"
                                    rows="4"
                                    placeholder="Add texture, flavor, plating, and portion notes."
                                />
                            </div>
                        </div>

                        <x-slot:footer>
                            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
                            <x-ui.button x-on:click="open = false">Continue</x-ui.button>
                        </x-slot:footer>
                    </x-ui.modal>
                </div>

                <div class="mt-4">
                    <x-ui.button
                        variant="secondary"
                        x-on:click="$dispatch('open-drawer', { id: 'ui-kit-dish-details-drawer' })"
                    >
                        Open Drawer
                    </x-ui.button>

                    <x-ui.drawer id="ui-kit-dish-details-drawer" title="Dish Details Preview" size="lg">
                        <div class="space-y-5">
                            <x-ui.image-placeholder label="Dish details image placeholder" ratio="video" />

                            <div class="space-y-2">
                                <x-ui.badge variant="warning" size="sm">Preview</x-ui.badge>
                                <h3 class="text-xl font-semibold text-white">Homemade or Restaurant?</h3>
                                <p class="text-sm leading-6 text-zinc-400">
                                    Crispy potato galette with herb cream, pickled onion, and a bright lemon finish.
                                </p>
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div class="rounded-lg border border-white/10 bg-zinc-900/70 p-4">
                                    <p class="text-xs font-medium uppercase tracking-normal text-zinc-500">Homemade</p>
                                    <p class="mt-2 text-2xl font-semibold text-white">64%</p>
                                </div>

                                <div class="rounded-lg border border-white/10 bg-zinc-900/70 p-4">
                                    <p class="text-xs font-medium uppercase tracking-normal text-zinc-500">Restaurant</p>
                                    <p class="mt-2 text-2xl font-semibold text-white">36%</p>
                                </div>
                            </div>

                            <div class="space-y-3 rounded-lg border border-white/10 bg-zinc-900/70 p-4">
                                <p class="text-sm font-semibold text-white">Comments preview</p>
                                <div class="space-y-3 text-sm text-zinc-300">
                                    <p class="rounded-md bg-zinc-950/70 px-3 py-2">Looks plated, but the cutting board says home kitchen.</p>
                                    <p class="rounded-md bg-zinc-950/70 px-3 py-2">The sauce work feels restaurant-level.</p>
                                </div>
                            </div>
                        </div>

                        <x-slot:footer>
                            <div class="flex items-center justify-end">
                                <x-ui.button
                                    variant="secondary"
                                    x-on:click="$dispatch('close-drawer', { id: 'ui-kit-dish-details-drawer' })"
                                >
                                    Close
                                </x-ui.button>
                            </div>
                        </x-slot:footer>
                    </x-ui.drawer>
                </div>
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
