@inject('postImagePresenter', \App\Support\Media\PostImagePresenter::class)
<div data-testid="post-drawer">
    <div wire:loading data-testid="post-drawer-loading" class="space-y-4 transition-opacity duration-200">
        {{-- Sizes are classes: the skeleton takes them as such. --}}
        <x-ui.skeleton shape="block" height="h-64" />
        <x-ui.skeleton shape="line" width="w-[70%]" />
        <x-ui.skeleton shape="line" width="w-[45%]" />
    </div>

    <div wire:loading.remove class="overflow-y-auto transition-opacity duration-200">
    @if($post)
        <article
            x-data="{ shareOpen: false, menuOpen: false, deleteOpen: false, imageOpen: false, menuId: $id('post-drawer-menu') }"
            {{-- The open menu takes Escape before the panel, wherever the focus is — Safari leaves
                 it on the panel after a click — and the panel stays open: as a dialog over it does.
                 Heard on the document, after the window: a dialog opened from the menu (Report)
                 takes that Escape on the window first and stops it there, leaving the menu open. --}}
            x-on:keydown.escape.document.capture="if (menuOpen) { menuOpen = false; $event.stopPropagation() }"
            x-on:dropdown-opened.window="if ($event.detail !== menuId) menuOpen = false"
            class="relative rounded-rgCard border border-rg-border bg-rg-card p-3 sm:p-4 lg:p-5"
        >
            @include('livewire.feed.post-drawer-close')

            <section class="flex min-w-0 items-start gap-3 pr-10" data-testid="post-drawer-meta">
                @if($post->user?->public_username)
                    <a href="{{ route('profile.show', $post->user->public_username) }}" wire:navigate class="shrink-0 rounded-full focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent">
                        <x-ui.avatar :src="$post->user?->resolved_avatar_url" :srcset="$post->user?->resolved_avatar_srcset" :name="$post->user->resolved_display_name" size="lg" />
                    </a>
                @else
                    <x-ui.avatar :src="$post->user?->resolved_avatar_url" :srcset="$post->user?->resolved_avatar_srcset" :name="$post->user?->resolved_display_name ?? __('ui.user.unknown')" size="lg" />
                @endif

                <div class="min-w-0">
                    @if($post->user?->public_username)
                        <a href="{{ route('profile.show', $post->user->public_username) }}" wire:navigate class="block truncate text-sm font-semibold text-rg-text hover:underline focus-visible:outline-none">{{ $post->user->resolved_display_name }}</a>
                    @else
                        <div class="truncate text-sm font-semibold text-rg-text">{{ $post->user?->resolved_display_name ?? __('ui.user.unknown') }}</div>
                    @endif

                    <div class="truncate text-xs text-rg-muted">
                        @if($post->user?->public_username)
                            {{ '@' . $post->user->public_username }}
                        @endif
                        @if($post->published_at)
                            {{ $post->user?->public_username ? ' · ' : '' }}{{ $post->published_at->diffForHumans() }}
                        @endif
                    </div>
                </div>

                @if($canSeeFollowButton)
                    <div class="ml-auto shrink-0" data-testid="post-drawer-follow" wire:click.stop>
                        <livewire:follows.follow-button :author="$post->user" variant="compact" :key="'post-drawer-follow-'.$post->id" />
                    </div>
                @endif
            </section>

            <h2 id="post-drawer-title" data-testid="post-drawer-title" class="mt-4 pr-10 text-[22px] font-bold tracking-normal text-rg-text">{{ $post->title }}</h2>

            @if($post->description)
                <p class="mt-3 break-words text-sm leading-relaxed text-rg-muted">{{ $post->description }}</p>
            @endif

            @if($post->category)
                <div class="mt-3">
                    <x-posts.category-link :category="$post->category" test-id="post-drawer-category" />
                </div>
            @endif

            <div class="mt-4">
                @if($post->public_image_url)
                    @php
                        $drawerImage = $postImagePresenter->responsive($post, \App\Enums\PostImageContext::Drawer);
                    @endphp
                    <x-media.post-image
                        :post="$post"
                        :src="$drawerImage?->src"
                        :srcset="$drawerImage?->srcset"
                        :sizes="$drawerImage?->sizes"
                        :width="$drawerImage?->width"
                        :height="$drawerImage?->height"
                        context="drawer"
                        open-fullscreen="imageOpen = true"
                        testid="post-drawer-image-open"
                        loading="lazy"
                    />
                @else
                    <x-ui.image-placeholder :label="__('ui.post.image_preview')" ratio="detail" />
                @endif
            </div>

            @if($post->tags->isNotEmpty())
                <div class="mt-3">
                    <x-posts.tag-links :tags="$post->tags" test-id="post-drawer-tags" />
                </div>
            @endif

            <footer class="mt-3 flex flex-wrap items-center justify-between gap-3 border-t border-rg-border pt-3">
                <div class="flex min-w-0 flex-wrap items-center gap-2 sm:gap-4">
                    <div data-testid="post-drawer-voting" wire:click.stop>
                        <livewire:posts.post-voting
                            :post-id="$post->id"
                            variant="pill"
                            :key="'post-detail-vote-pill-'.$post->id"
                        />
                    </div>
                    @if($projectSettings->featureEnabled('show_comments'))
                    <x-ui.action-button
                        icon="comment"
                        data-testid="post-drawer-comments"
                        {{-- Only a scroll to the comments: post-selected would select the post
                             again, and the feed would scroll back to its card. --}}
                        x-on:click="$dispatch('post-comments-requested', { postId: {{ $post->id }} })"
                    >
                        {{ $post->comments_count ?? 0 }}
                    </x-ui.action-button>
                    @endif
                    @if($projectSettings->featureEnabled('show_share_buttons'))
                    <x-ui.action-button icon="share" x-on:click="shareOpen = true" data-testid="post-drawer-share">{{ __('ui.share.title') }}</x-ui.action-button>
                    @endif
                    @if($projectSettings->featureEnabled('show_saved_posts'))
                    @auth
                        <livewire:posts.save-post-button
                            :post-id="$post->id"
                            :key="'post-drawer-save-'.$post->id"
                        />
                    @endauth
                    @endif
                </div>
                @if($canReportPost || $canDeletePost || $canModeratePost)
                <div class="relative" wire:click.stop>
                    <button
                        type="button"
                        x-on:click="menuOpen = ! menuOpen; if (menuOpen) $dispatch('dropdown-opened', menuId)"
                        class="cursor-pointer rounded-rgSm p-1 text-rg-muted transition hover:bg-rg-card2 hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
                        aria-label="{{ __('ui.a11y.post_actions') }}"
                    >
                        <x-ui.icon name="more" class="size-4" />
                    </button>

                    <div
                        x-cloak
                        x-show="menuOpen"
                        x-on:click.outside="menuOpen = false"
                        data-testid="post-drawer-menu"
                        class="absolute right-0 top-full z-20 mt-2 w-44 rounded-rgControl border border-rg-border bg-rg-card2 p-1 shadow-rgDropdown"
                    >
                        @if($canReportPost)
                            <div>
                                <livewire:reports.report-modal
                                    reportable-type="post"
                                    :reportable-id="$post->id"
                                    variant="menu"
                                    :key="'post-drawer-menu-report-'.$post->id"
                                />
                            </div>
                        @endif

                        @if($canDeletePost)
                            <button
                                type="button"
                                x-on:click="menuOpen = false; deleteOpen = true"
                                class="flex w-full cursor-pointer items-center rounded-rgSm px-3 py-1.5 text-left text-sm font-semibold text-rg-dangerText transition hover:bg-rg-dangerSoft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-dangerText"
                            >
                                {{ __('ui.post.delete') }}
                            </button>
                        @endif

                        <livewire:moderation.inline-post-moderation
                            :post-id="$post->id"
                            variant="menu"
                            :key="'post-drawer-menu-moderation-'.$post->id"
                        />

                    </div>
                </div>
                @endif
            </footer>

            @if($projectSettings->featureEnabled('show_share_buttons'))
            <x-ui.modal title="{{ __('sharing.share_this_post') }}" state="shareOpen" size="lg" close-on-escape>
                <x-sharing.share-buttons :post="$post" />
            </x-ui.modal>
            @endif

            @if($post->public_image_url)
                @php
                    $fullscreenImage = $postImagePresenter->responsive($post, \App\Enums\PostImageContext::Fullscreen);
                @endphp
                <x-ui.modal title="{{ $post->title }}" state="imageOpen" size="fullscreen" below-header close-on-escape>
                    <x-media.post-image
                        :post="$post"
                        :src="$fullscreenImage?->src"
                        :srcset="$fullscreenImage?->srcset"
                        :sizes="$fullscreenImage?->sizes"
                        :width="$fullscreenImage?->width"
                        :height="$fullscreenImage?->height"
                        context="fullscreen"
                        image-testid="post-fullscreen-image"
                        loading="lazy"
                    />
                </x-ui.modal>
            @endif

            <x-ui.modal title="{{ __('ui.post.delete_confirm_title') }}" state="deleteOpen" size="sm" close-on-escape>
                <div class="space-y-4">
                    <p class="text-sm leading-6 text-rg-muted">{{ __('ui.post.delete_confirm_description', ['days' => \App\Support\Posts\PostRetention::days()]) }}</p>

                    @if($deleteError)
                        <p class="text-sm text-rg-dangerText">{{ $deleteError }}</p>
                    @endif

                    <div class="flex justify-end gap-2">
                        <x-ui.button type="button" variant="ghost" x-on:click="deleteOpen = false">{{ __('ui.actions.cancel') }}</x-ui.button>
                        <x-ui.button
                            type="button"
                            variant="danger"
                            wire:click="deleteSelectedPost"
                            wire:loading.attr="disabled"
                            wire:target="deleteSelectedPost"
                        >
                            {{ __('ui.actions.delete') }}
                        </x-ui.button>
                    </div>
                </div>
            </x-ui.modal>
        </article>

        @if($activeRatingGroups->isNotEmpty())
        <section data-testid="post-detail-results" class="mt-4 min-w-0 space-y-5 rounded-rgCard border border-rg-border bg-rg-card p-3 sm:p-4 lg:p-5">
            @foreach($activeRatingGroups as $ratingGroup)
                <div class="{{ $loop->first ? '' : 'border-t border-rg-border pt-4' }}" wire:click.stop>
                    <livewire:voting.rating-voting
                        :post="$post"
                        :group-key="$ratingGroup->key"
                        :group="$ratingGroup"
                        :variant="$ratingGroup->options->count() <= 2 ? 'default' : 'compact'"
                        :key="'drawer-rating-'.$ratingGroup->key.'-'.$post->id"
                    />
                </div>
            @endforeach
        </section>
        @endif

        @if($projectSettings->featureEnabled('show_comments'))
        <section class="mt-4" data-testid="drawer-comments-slot">
            <livewire:comments.comments-section :post-id="$post->id" :show-header="true" :key="'drawer-comments-'.$post->id" />
        </section>
        @endif
    @elseif($postId)
        <div class="relative pt-12">
            @include('livewire.feed.post-drawer-close')

            <x-ui.error-message
                :title="__('ui.post.not_found_title')"
                :message="__('ui.post.not_found_description')"
            />
        </div>
    @else
        <x-ui.empty-state
            :title="__('ui.post.select_title')"
            :description="__('ui.post.select_description')"
        />
    @endif
    </div>
</div>
