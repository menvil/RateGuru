@php
    $sortLabels = [
        'top' => __('ui.comments.sort.top'),
        'newest' => __('ui.comments.sort.newest'),
        'hot' => __('ui.comments.sort.hot'),
    ];
@endphp
<section data-testid="comments-section" class="min-w-0 rounded-rgCard border border-rg-border bg-rg-card p-5">
    @if ($showHeader)
        <div class="flex items-center justify-between gap-3">
            <h3 class="text-base font-bold text-rg-text">{{ __('ui.comments.title_with_count', ['count' => $this->totalComments]) }}</h3>
            <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false">
                <button
                    type="button"
                    x-on:click="open = ! open"
                    class="flex h-8 cursor-pointer items-center gap-1.5 rounded-rgSm border border-rg-border2 bg-rg-card2 px-2.5 text-[12.5px] text-rg-text2 transition hover:bg-rg-cardHover hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
                    data-testid="comments-sort-trigger"
                    aria-haspopup="true"
                    aria-controls="comments-sort-menu"
                    x-bind:aria-expanded="open"
                >
                    {{ $sortLabels[$commentSort] ?? $sortLabels['top'] }}
                    <x-ui.icon name="chevron-down" class="size-3.5" />
                </button>

                <div
                    id="comments-sort-menu"
                    x-cloak
                    x-show="open"
                    class="absolute right-0 z-20 mt-2 w-32 rounded-rgControl border border-rg-border bg-rg-card2 p-1 shadow-rgDropdown"
                    data-testid="comments-sort-menu"
                >
                    @foreach($sortLabels as $value => $label)
                        <button
                            type="button"
                            wire:click="setCommentSort('{{ $value }}')"
                            x-on:click="open = false"
                            aria-pressed="{{ $commentSort === $value ? 'true' : 'false' }}"
                            class="block w-full cursor-pointer rounded-rgSm px-3 py-1.5 text-left text-[12.5px] transition {{ $commentSort === $value ? 'bg-rg-accentSoft text-rg-accent2' : 'text-rg-text2 hover:bg-rg-card' }}"
                        >
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    <div class="{{ $showHeader ? 'mt-4 mb-[22px]' : 'mb-[22px]' }}">
        <livewire:comments.comment-form :post-id="$postId" :key="'comment-form-'.$postId" />
    </div>

    <div
        wire:loading
        wire:target="deleteComment,hideComment,refreshComments,submitReply,loadMore"
        data-testid="comments-loading"
        class="space-y-2 transition-opacity duration-200"
    >
        <x-ui.skeleton shape="line" width="w-3/4" />
        <x-ui.skeleton shape="line" width="w-1/2" />
    </div>

    @if ($this->comments->isEmpty())
        <x-ui.empty-state
            title="{{ __('ui.comments.empty_title') }}"
            description="{{ __('ui.comments.empty_description') }}"
            data-testid="comments-empty"
        />
    @else
        <div class="space-y-4">
            @foreach ($this->comments as $comment)
                <x-comments.comment-item
                    :comment="$comment"
                    :can-delete="$this->canDeleteComment($comment)"
                    :can-hide="$this->userCanHideComments()"
                    can-reply
                    wire:key="comment-{{ $comment->id }}"
                />

                @if($replyingTo === $comment->id)
                    <form
                        wire:submit.prevent="submitReply"
                        class="ml-4 flex min-h-[50px] items-center gap-2.5 rounded-[10px] border border-rg-border2 bg-rg-card2 py-2 pl-3.5 pr-2"
                        data-testid="reply-form"
                    >
                        <input
                            name="replyBody"
                            type="text"
                            wire:model="replyBody"
                            aria-label="{{ __('ui.comments.write_reply') }}"
                            maxlength="1000"
                            placeholder="{{ __('ui.comments.write_reply') }}"
                            @class([
                                'rg-comment-input h-8 flex-1 appearance-none border-0 bg-transparent p-0 text-[13.5px] text-rg-text shadow-none outline-none ring-0 placeholder:text-rg-muted focus:border-0 focus:outline-none focus:ring-0 focus-visible:border-0 focus-visible:outline-none focus-visible:ring-0',
                                'text-rg-dangerText' => $errors->has('replyBody'),
                            ])
                        >

                        @error('replyBody')
                            <p class="text-xs text-rg-dangerText" role="alert" aria-live="polite">{{ $message }}</p>
                        @enderror

                        <x-ui.button
                            type="button"
                            size="sm"
                            variant="secondary"
                            class="h-8 rounded-rgSm border border-rg-border2 bg-rg-card px-3 text-[12.5px]"
                            wire:click="cancelReply"
                        >
                            {{ __('ui.comments.cancel') }}
                        </x-ui.button>
                        <x-ui.button
                            type="submit"
                            size="sm"
                            class="h-8 rounded-rgSm px-4 text-[12.5px]"
                            wire:loading.attr="disabled"
                            wire:target="submitReply"
                        >
                            {{ __('ui.comments.reply') }}
                        </x-ui.button>
                    </form>
                @endif

                @if($comment->replies->isNotEmpty())
                    <div class="ml-4 space-y-4 border-l border-rg-border pl-4" data-testid="comment-replies">
                        @foreach($comment->replies as $reply)
                            <x-comments.comment-item
                                :comment="$reply"
                                :can-delete="$this->canDeleteComment($reply)"
                                :can-hide="$this->userCanHideComments()"
                                wire:key="comment-reply-{{ $reply->id }}"
                            />
                        @endforeach
                    </div>
                @endif
            @endforeach
        </div>

        @if($this->comments->count() < $this->totalTopLevelComments)
            <button
                type="button"
                wire:click="loadMore"
                data-testid="view-more-comments"
                class="w-full cursor-pointer rounded-rgControl border border-rg-border2 bg-rg-card2 px-4 py-2 text-sm font-semibold text-rg-text2 transition hover:border-rg-accent hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
            >
                {{ __('ui.comments.view_more') }}
            </button>
        @endif
    @endif
</section>
