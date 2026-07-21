@extends('layouts.app')

@section('content')
    <section class="space-y-8">
        <div class="max-w-3xl">
            <p class="text-sm font-medium uppercase tracking-normal text-rg-accent2">Development</p>
            <h1 class="mt-3 text-3xl font-semibold text-rg-text sm:text-4xl">
                RateGuru UI Kit
            </h1>
            <p class="mt-3 text-sm text-rg-muted">
                Visual contract for PlateRate reference direction.
            </p>
        </div>

        @include('dev.partials.platerate-reference-composition')

        <section class="space-y-4">
            <div>
                <h2 class="text-xl font-bold text-rg-text">Product Components</h2>
                <p class="mt-1 text-sm text-rg-muted">Static product-level examples used by the reference composition.</p>
            </div>

            <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_420px]">
                <div class="space-y-4">
                    @include('dev.partials.platerate-post-card', [
                        'selected' => true,
                        'user' => 'sample_author',
                        'time' => '2h ago',
                        'score' => '128',
                        'title' => 'Post Card Shell',
                        'imageLabel' => 'SAMPLE POST · IMAGE 01',
                        'imagePalette' => 'warm',
                        'comments' => '34',
                        'avatarColor' => 'purple',
                    ])

                    <x-ui.card variant="selected-post">
                        <p class="text-sm font-semibold text-rg-text">Selected Post Card Shell</p>
                        <p class="mt-2 text-sm text-rg-muted">Selected state uses accent border and selected ring.</p>
                    </x-ui.card>

                    <x-ui.image-placeholder label="Image Placeholder" palette="green" ratio="feed" />

                    <div class="rounded-rgCard border border-rg-border bg-rg-card p-4">
                        <p class="mb-3 text-sm font-semibold text-rg-text">Vote Rail / Binary Choice / Rating Option Chips</p>
                        <div class="flex items-center gap-5">
                            <x-ui.vote-rail score="128" active="up" />
                            <div class="flex-1 space-y-3">
                                <x-ui.binary-choice selected="option_a" />
                                <div class="flex flex-wrap gap-2">
                                    <x-ui.rating-option-chip active>A</x-ui.rating-option-chip>
                                    <x-ui.rating-option-chip>B</x-ui.rating-option-chip>
                                    <x-ui.rating-option-chip>C</x-ui.rating-option-chip>
                                    <x-ui.rating-option-chip>D</x-ui.rating-option-chip>
                                    <x-ui.rating-option-chip>OT</x-ui.rating-option-chip>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="space-y-4">
                    @include('dev.partials.platerate-detail-post')
                    @include('dev.partials.platerate-results-panel')
                    @include('dev.partials.platerate-comments-panel')
                    <x-share.post-share-panel :post="$demoPost" url="https://rateguru.test/posts/preview" />
                </div>
            </div>
        </section>

        <section class="space-y-4">
            <div>
                <h2 class="text-xl font-bold text-rg-text">Feed Components</h2>
                <p class="mt-1 text-sm text-rg-muted">Live feed components driven by real Post data shape.</p>
            </div>

            <div class="max-w-xl space-y-4">
                <x-feed.post-card :post="$demoPost" />

                <x-voting.rating-options
                    :group="$demoRatingGroup"
                    :options="$demoRatingGroup->options"
                    :selected-option-id="1"
                />
            </div>
        </section>

        <section class="space-y-4">
            <div>
                <h2 class="text-xl font-bold text-rg-text">Primitive Components</h2>
                <p class="mt-1 text-sm text-rg-muted">Reusable components remain available below the product reference.</p>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <section class="rounded-rgCard border border-rg-border bg-rg-surface p-5">
                    <h3 class="text-base font-semibold text-rg-text">Buttons</h3>

                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <x-ui.button>Primary Button</x-ui.button>
                        <x-ui.button variant="secondary">Secondary Button</x-ui.button>
                        <x-ui.button variant="ghost">Ghost Button</x-ui.button>
                        <x-ui.button variant="danger">Danger Button</x-ui.button>
                        <x-ui.button disabled>Disabled Button</x-ui.button>
                        <x-ui.button elevated>
                            <x-ui.icon name="upload" class="size-4" />
                            Upload Button
                        </x-ui.button>
                        <x-ui.rating-option-chip active>Rating Option Active</x-ui.rating-option-chip>
                        <x-ui.rating-option-chip>Rating Option Inactive</x-ui.rating-option-chip>
                        <x-ui.action-button icon="comment">Action Button</x-ui.action-button>
                    </div>
                </section>

                <section class="rounded-rgCard border border-rg-border bg-rg-surface p-5">
                    <h3 class="text-base font-semibold text-rg-text">Cards</h3>

                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <x-ui.card variant="panel">
                            <p class="text-sm font-semibold text-rg-text">Panel Card</p>
                            <p class="mt-2 text-sm text-rg-muted">General dark panel surface.</p>
                        </x-ui.card>

                        <x-ui.card variant="post">
                            <p class="text-sm font-semibold text-rg-text">Post Card Shell</p>
                            <p class="mt-2 text-sm text-rg-muted">Feed card anatomy container.</p>
                        </x-ui.card>

                        <x-ui.card variant="selected-post">
                            <p class="text-sm font-semibold text-rg-text">Selected Post Card Shell</p>
                            <p class="mt-2 text-sm text-rg-muted">Accent selected state.</p>
                        </x-ui.card>

                        <x-ui.card variant="results">
                            <p class="text-sm font-semibold text-rg-text">Results Card</p>
                            <p class="mt-2 text-sm text-rg-muted">Results and metrics surface.</p>
                        </x-ui.card>

                        <x-ui.card variant="comment">
                            <p class="text-sm font-semibold text-rg-text">Comment Card</p>
                            <p class="mt-2 text-sm text-rg-muted">Comment thread surface.</p>
                        </x-ui.card>
                    </div>
                </section>

                <section class="rounded-rgCard border border-rg-border bg-rg-surface p-5">
                    <h3 class="text-base font-semibold text-rg-text">Forms</h3>

                    <div class="mt-4 space-y-4">
                        <div class="space-y-2">
                            <label for="ui-kit-form-post-title" class="text-sm font-medium text-rg-text">Post title</label>
                            <x-ui.input id="ui-kit-form-post-title" name="form_post_title" placeholder="A clear post title" />
                        </div>

                        <div class="space-y-2">
                            <label for="ui-kit-disabled-input" class="text-sm font-medium text-rg-text">Disabled input</label>
                            <x-ui.input id="ui-kit-disabled-input" name="disabled_input" value="Locked preview value" disabled />
                        </div>

                        <div class="space-y-2">
                            <label for="ui-kit-error-input" class="text-sm font-medium text-rg-text">Input with error</label>
                            <x-ui.input id="ui-kit-error-input" name="error_input" value="Missing rating context" error />
                        </div>

                        <div class="space-y-2">
                            <label for="ui-kit-form-description" class="text-sm font-medium text-rg-text">Description</label>
                            <x-ui.textarea id="ui-kit-form-description" name="form_description" rows="4" placeholder="Describe taste, texture, and plating." />
                        </div>

                        <x-ui.input
                            name="comment_composer"
                            placeholder="Comment composer"
                            aria-label="Comment composer"
                        />

                        <div class="rounded-rgCard border border-dashed border-rg-accentBorder bg-rg-accentSoft p-4 text-sm font-semibold text-rg-accent2">
                            Upload dropzone
                        </div>

                        <x-ui.error-message title="Validation error example" message="Post title must be specific enough for voters." />
                    </div>
                </section>

                <section class="rounded-rgCard border border-rg-border bg-rg-surface p-5">
                    <h3 class="text-base font-semibold text-rg-text">Overlays</h3>

                    <div class="mt-4 flex flex-wrap items-center gap-3" x-data="{ open: false }">
                        <x-ui.button x-on:click="open = true">Open Modal</x-ui.button>

                        <x-ui.modal title="Upload Post Preview" size="lg">
                            <div class="space-y-4">
                                <p class="text-sm font-semibold text-rg-text">Create post / Upload image</p>

                                <div class="grid grid-cols-2 gap-2 rounded-rgControl bg-rg-card2 p-1">
                                    <button type="button" class="rounded-rgSm bg-rg-accent px-3 py-2 text-xs font-semibold text-rg-onAccent">Photo</button>
                                    <button type="button" class="rounded-rgSm px-3 py-2 text-xs font-semibold text-rg-text2">Link</button>
                                </div>

                                <div class="rounded-rgCard border border-dashed border-rg-accentBorder bg-rg-accentSoft p-5 text-center text-sm font-semibold text-rg-accent2">
                                    Drop post image here
                                </div>

                                <x-ui.input name="modal_title" placeholder="Post title" />
                                <x-ui.textarea name="modal_description" rows="3" placeholder="Description" />

                                <div class="flex flex-wrap gap-2">
                                    <x-ui.badge variant="accent">#sample</x-ui.badge>
                                    <x-ui.badge variant="neutral">#popular</x-ui.badge>
                                </div>
                            </div>

                            <x-slot:footer>
                                <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
                                <x-ui.button x-on:click="open = false">Publish</x-ui.button>
                            </x-slot:footer>
                        </x-ui.modal>
                    </div>

                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <x-ui.button variant="secondary" x-on:click="$dispatch('open-drawer', { id: 'ui-kit-mobile-drawer' })">
                            Open Drawer
                        </x-ui.button>

                        <x-ui.dropdown>
                            <x-slot:trigger>
                                <x-ui.button variant="secondary">Dropdown / Sort menu</x-ui.button>
                            </x-slot:trigger>
                            <x-slot:content>
                                <button type="button" class="block w-full rounded-rgSm px-3 py-2 text-left text-sm text-rg-text2 hover:bg-rg-card2">Top</button>
                                <button type="button" class="block w-full rounded-rgSm px-3 py-2 text-left text-sm text-rg-text2 hover:bg-rg-card2">New</button>
                                <button type="button" class="block w-full rounded-rgSm px-3 py-2 text-left text-sm text-rg-text2 hover:bg-rg-card2">Hot</button>
                            </x-slot:content>
                        </x-ui.dropdown>

                        <x-ui.drawer id="ui-kit-mobile-drawer" title="Post Details Preview" size="lg">
                            <div class="space-y-5">
                                <x-ui.image-placeholder label="SAMPLE POST · IMAGE 01" palette="warm" ratio="video" />
                                <h4 class="text-lg font-bold text-rg-text">Which option fits best?</h4>
                                <x-ui.binary-choice selected="option_a" />
                                <p class="text-sm text-rg-muted">Mobile drawer preview; desktop reference uses the fixed right detail column above.</p>
                            </div>
                        </x-ui.drawer>
                    </div>
                </section>

                <section class="rounded-rgCard border border-rg-border bg-rg-surface p-5">
                    <h3 class="text-base font-semibold text-rg-text">Feedback</h3>

                    <div class="mt-4 space-y-4">
                        <x-ui.error-message title="Error message" message="Something needs attention before publishing." />
                        <x-ui.empty-state title="Empty state" message="No posts match this filter yet." />
                        <x-ui.skeleton class="h-16 w-full rounded-rgCard" />
                    </div>
                </section>

                <section class="rounded-rgCard border border-rg-border bg-rg-surface p-5">
                    <h3 class="text-base font-semibold text-rg-text">Comments</h3>

                    @php
                        $uiKitComment = new \App\Models\Comment([
                            'body' => 'Looks delicious.',
                            'created_at' => now()->subMinutes(5),
                        ]);

                        $uiKitComment->setRelation('user', new \App\Models\User([
                            'name' => 'Demo User',
                            'username' => 'demo_user',
                        ]));
                    @endphp

                    <div class="mt-4 space-y-4">
                        <p class="text-xs uppercase tracking-wide text-rg-muted">Comment Item</p>
                        <x-comments.comment-item :comment="$uiKitComment" />
                    </div>
                </section>

                <section class="rounded-rgCard border border-rg-border bg-rg-surface p-5">
                    <h3 class="text-base font-semibold text-rg-text">Reference</h3>

                    <ul class="mt-4 space-y-2 text-sm text-rg-text2">
                        <li>docs/design/reference/original/PlateRate.html</li>
                        <li>docs/design/design-contract.md</li>
                        <li>docs/design/ui-review-checklist.md</li>
                        <li>docs/design/visual-baselines.md</li>
                    </ul>
                </section>
            </div>
        </section>
    </section>
@endsection
