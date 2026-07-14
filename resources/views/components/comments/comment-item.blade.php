<article
    id="comment-{{ $comment->id }}"
    data-testid="comment-item"
    class="grid grid-cols-[32px_minmax(0,1fr)] gap-2.5 text-[13px]"
    x-data="{ actionsOpen: false, menuId: $id('comment-actions-menu') }"
    x-on:keydown.escape.window="actionsOpen = false"
    x-on:dropdown-opened.window="if ($event.detail !== menuId) actionsOpen = false"
>
    @if($comment->user?->username)
        <a href="{{ route('profile.show', $comment->user->username) }}" wire:navigate class="shrink-0 self-start rounded-full focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent">
            <x-ui.avatar :src="$comment->user?->avatar_url" :name="$comment->user->name" size="md" />
        </a>
    @else
        <x-ui.avatar :src="$comment->user?->avatar_url" :name="$comment->user?->name ?? __('ui.user.unknown')" size="md" />
    @endif

    <div class="min-w-0">
        <div class="flex min-w-0 items-start justify-between gap-2">
            <p class="min-w-0">
                @if($comment->user?->username)
                    <a href="{{ route('profile.show', $comment->user->username) }}" wire:navigate class="font-semibold text-rg-text hover:underline focus-visible:outline-none">
                        {{ '@'.$comment->user->username }}
                    </a>
                @else
                    <span class="font-semibold text-rg-text">{{ $comment->user?->name ?? __('ui.user.unknown') }}</span>
                @endif

                @if ($comment->created_at)
                    <time datetime="{{ $comment->created_at->toIso8601String() }}" class="text-xs text-rg-muted">
                        {{ $comment->created_at->diffForHumans() }}
                    </time>
                @endif
            </p>

            @if($hasMenuActions)
                <div class="relative -mt-1 inline-flex shrink-0" wire:click.stop wire:keydown.stop>
                    <button
                        type="button"
                        aria-label="{{ __('ui.a11y.comment_actions') }}"
                        aria-controls="comment-actions-{{ $comment->id }}"
                        x-bind:aria-expanded="actionsOpen"
                        x-on:click="actionsOpen = ! actionsOpen; if (actionsOpen) $dispatch('dropdown-opened', menuId)"
                        class="cursor-pointer rounded-rgSm p-1 text-rg-muted transition hover:bg-rg-card2 hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
                    >
                        <x-ui.icon name="more" class="size-4" />
                    </button>

                    <div
                        id="comment-actions-{{ $comment->id }}"
                        x-cloak
                        x-show="actionsOpen"
                        x-on:click.outside="actionsOpen = false"
                        class="absolute right-0 top-full z-20 mt-2 w-40 rounded-rgControl border border-rg-border bg-rg-card2 p-1 shadow-rgDropdown"
                    >
                        @if($canReport)
                            <div data-testid="comment-report">
                                <livewire:reports.report-modal
                                    reportable-type="comment"
                                    :reportable-id="$comment->id"
                                    variant="menu"
                                    :key="'comment-report-'.$comment->id"
                                    wire:lazy
                                />
                            </div>
                        @endif

                        @if ($canHide)
                            <button
                                type="button"
                                wire:click="hideComment({{ $comment->id }})"
                                wire:confirm="{{ __('ui.comments.hide') }}?"
                                x-on:click="actionsOpen = false"
                                class="block w-full cursor-pointer rounded-rgSm px-3 py-1.5 text-left text-sm font-semibold text-rg-muted transition hover:bg-rg-dangerSoft hover:text-rg-dangerText"
                            >
                                {{ __('ui.comments.hide') }}
                            </button>
                        @endif

                        @if ($canDelete)
                            <button
                                type="button"
                                wire:click="deleteComment({{ $comment->id }})"
                                wire:confirm="{{ __('ui.comments.delete') }}?"
                                x-on:click="actionsOpen = false"
                                class="block w-full cursor-pointer rounded-rgSm px-3 py-1.5 text-left text-sm font-semibold text-rg-muted transition hover:bg-rg-dangerSoft hover:text-rg-dangerText"
                            >
                                {{ __('ui.comments.delete') }}
                            </button>
                        @endif
                    </div>
                </div>
            @endif
        </div>

        <p class="mt-1 break-words leading-5 text-rg-text2">{{ $comment->body }}</p>

        <div class="mt-2 flex items-center gap-3.5 text-[12.5px] text-rg-muted" wire:click.stop wire:keydown.stop>
            @if($comment->exists)
                <livewire:comments.comment-voting
                    :comment-id="$comment->id"
                    :key="'comment-voting-'.$comment->id"
                />
            @else
                <div class="flex items-center gap-1.5">
                    <button
                        type="button"
                        aria-label="{{ __('ui.a11y.vote_up') }}"
                        class="cursor-pointer bg-transparent p-0.5 text-rg-muted transition hover:text-rg-good focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
                    >
                        <x-ui.icon name="arrow-up" class="size-3.5" />
                    </button>
                    <span class="text-[12.5px] font-semibold text-rg-text2">0</span>
                    <button
                        type="button"
                        aria-label="{{ __('ui.a11y.vote_down') }}"
                        class="cursor-pointer bg-transparent p-0.5 text-rg-muted transition hover:text-rg-accent2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
                    >
                        <x-ui.icon name="arrow-down" class="size-3.5" />
                    </button>
                </div>
            @endif

            @auth
                @if($canReply)
                    <button
                        type="button"
                        wire:click="startReply({{ $comment->id }})"
                        class="flex cursor-pointer items-center gap-1 bg-transparent p-0 text-[12.5px] text-rg-muted transition hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
                    >
                        <x-ui.icon name="reply" class="size-[13px]" />
                        {{ __('ui.comments.reply') }}
                    </button>
                @endif
            @endauth
        </div>
    </div>
</article>
