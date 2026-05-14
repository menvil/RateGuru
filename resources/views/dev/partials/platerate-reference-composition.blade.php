<section class="space-y-4">
    <div>
        <h2 class="text-xl font-bold text-rg-text">PlateRate Reference Composition</h2>
        <p class="mt-1 text-sm text-rg-muted">Static visual contract for the Phase 1 product direction.</p>
    </div>

    <div data-ui="platerate-shell" class="mx-auto h-[820px] max-w-[1440px] overflow-hidden rounded-2xl border border-rg-border bg-rg-bg shadow-rgPopover">
        @include('dev.partials.platerate-topbar')

        <div class="grid h-[calc(100%-60px)] grid-cols-[240px_1fr]">
            @include('dev.partials.platerate-sidebar')

            <div class="grid min-w-0 grid-cols-[minmax(520px,1fr)_minmax(380px,460px)]">
                <main data-ui="platerate-feed" class="overflow-y-auto border-r border-rg-border bg-rg-feed px-6 py-5">
                    @include('dev.partials.platerate-feed-tabs')

                    <div class="space-y-4">
                        @include('dev.partials.platerate-post-card', [
                            'selected' => true,
                            'user' => 'pasta_lover',
                            'time' => '2h ago',
                            'score' => '128',
                            'title' => 'Homemade or restaurant?',
                            'dishLabel' => 'CARBONARA · 4 servings',
                            'dishPalette' => 'carbonara',
                            'comments' => '34',
                            'avatarColor' => 'purple',
                        ])

                        @include('dev.partials.platerate-post-card', [
                            'selected' => false,
                            'user' => 'fit_guy',
                            'time' => '5h ago',
                            'score' => '89',
                            'title' => 'Real or AI?',
                            'dishLabel' => 'MATCHA · plated set',
                            'dishPalette' => 'matcha',
                            'comments' => '18',
                            'avatarColor' => 'green',
                        ])
                    </div>
                </main>

                @include('dev.partials.platerate-detail-column')
            </div>
        </div>
    </div>
</section>
