@props([
    'comment',
    'canDelete' => false,
    'canHide' => false,
    'canReply' => false,
])

@php
    $canReport = $comment->exists && auth()->id() !== $comment->user_id;
    $hasMenuActions = $canDelete || $canHide || $canReport;
@endphp

<article
    data-testid="comment-item"
    class="grid grid-cols-[32px_minmax(0,1fr)] gap-2.5 text-[13px]"
    x-data="{ actionsOpen: false }"
>
    <x-ui.avatar
        :src="$comment->user?->avatar_url"
        :name="$comment->user?->name ?? 'User'"
        size="md"
    />

    <div class="min-w-0">
        <div class="flex min-w-0 items-start justify-between gap-2">
            <p class="min-w-0">
                <span class="font-semibold text-rg-text">
                    {{ $comment->user?->username ? '@'.$comment->user->username : ($comment->user?->name ?? 'Unknown user') }}
                </span>

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
                        aria-label="Comment actions"
                        x-on:click="actionsOpen = ! actionsOpen"
                        class="cursor-pointer rounded-rgSm p-1 text-rg-muted transition hover:bg-rg-card2 hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
                    >
                        <x-ui.icon name="more" class="size-4" />
                    </button>

                    <div
                        x-cloak
                        x-show="actionsOpen"
                        x-on:click.outside="actionsOpen = false"
                        class="absolute right-0 top-full z-20 mt-2 w-40 rounded-rgControl border border-rg-border bg-rg-card2 p-1 shadow-rgDropdown"
                    >
                        @if($canReport)
                            <div data-testid="comment-report" class="rounded-rgSm px-3 py-1.5 transition hover:bg-rg-card">
                                <livewire:reports.report-modal
                                    reportable-type="comment"
                                    :reportable-id="$comment->id"
                                    :key="'comment-report-'.$comment->id"
                                />
                            </div>
                        @endif

                        @if ($canHide)
                            <button
                                type="button"
                                wire:click="hideComment({{ $comment->id }})"
                                wire:confirm="Hide this comment?"
                                x-on:click="actionsOpen = false"
                                class="block w-full cursor-pointer rounded-rgSm px-3 py-1.5 text-left text-sm font-semibold text-rg-muted transition hover:bg-rg-dangerSoft hover:text-rg-dangerText"
                            >
                                Hide
                            </button>
                        @endif

                        @if ($canDelete)
                            <button
                                type="button"
                                wire:click="deleteComment({{ $comment->id }})"
                                wire:confirm="Delete this comment?"
                                x-on:click="actionsOpen = false"
                                class="block w-full cursor-pointer rounded-rgSm px-3 py-1.5 text-left text-sm font-semibold text-rg-muted transition hover:bg-rg-dangerSoft hover:text-rg-dangerText"
                            >
                                Delete
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
                        aria-label="Vote up"
                        class="cursor-pointer bg-transparent p-0.5 text-rg-muted transition hover:text-rg-good focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
                    >
                        <x-ui.icon name="arrow-up" class="size-3.5" />
                    </button>
                    <span class="text-[12.5px] font-semibold text-rg-text2">0</span>
                    <button
                        type="button"
                        aria-label="Vote down"
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
                        Reply
                    </button>
                @endif
            @endauth
        </div>
    </div>
</article>
