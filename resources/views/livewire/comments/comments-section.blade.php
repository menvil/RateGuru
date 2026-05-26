<section data-testid="comments-section" class="rounded-rgCard border border-rg-border bg-rg-card p-5">
    @if ($showHeader)
        <div class="flex items-center justify-between gap-3">
            <h3 class="text-base font-bold text-rg-text">Comments ({{ $this->totalComments }})</h3>
            <button type="button" class="cursor-pointer text-xs font-semibold text-rg-text2 transition hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent">
                Top
            </button>
        </div>
    @endif

    <div class="{{ $showHeader ? 'mt-4' : '' }}">
        <livewire:comments.comment-form :post-id="$postId" :key="'comment-form-'.$postId" />
    </div>

    <div
        wire:loading
        wire:target="deleteComment,hideComment,refreshComments,submitReply,loadMore"
        data-testid="comments-loading"
        class="mt-5 space-y-2 transition-opacity duration-200"
    >
        <x-ui.skeleton shape="line" width="w-3/4" />
        <x-ui.skeleton shape="line" width="w-1/2" />
    </div>

    @if ($this->comments->isEmpty())
        <div class="mt-5">
            <x-ui.empty-state
                title="No comments yet"
                description="Be the first to comment."
            />
        </div>
    @else
        <div class="mt-5 space-y-4">
            @foreach ($this->comments as $comment)
                <x-comments.comment-item
                    :comment="$comment"
                    :can-delete="$this->canDeleteComment($comment)"
                    :can-hide="$this->userCanHideComments()"
                    can-reply
                    wire:key="comment-{{ $comment->id }}"
                />

                @if($replyingTo === $comment->id)
                    <form wire:submit.prevent="submitReply" class="ml-4 flex min-h-[42px] items-center gap-2 border-l border-rg-border pl-4" data-testid="reply-form">
                        <textarea
                            name="replyBody"
                            wire:model="replyBody"
                            rows="1"
                            maxlength="1000"
                            placeholder="Write a reply..."
                            @class([
                                'min-h-[32px] flex-1 resize-none bg-transparent py-1 text-[13px] leading-5 text-rg-text placeholder:text-rg-muted focus-visible:outline-none',
                                'text-rg-dangerText' => $errors->has('replyBody'),
                            ])
                        ></textarea>

                        @error('replyBody')
                            <p class="sr-only">{{ $message }}</p>
                        @enderror

                        <x-ui.button type="button" size="sm" variant="ghost" wire:click="cancelReply">Cancel</x-ui.button>
                        <x-ui.button type="submit" size="sm" wire:loading.attr="disabled" wire:target="submitReply">Reply</x-ui.button>
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
                View more comments
            </button>
        @endif
    @endif
</section>
