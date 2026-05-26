<div>
    @auth
        <form
            data-testid="comment-form"
            wire:submit.prevent="submit"
            class="flex min-h-[42px] items-center gap-2 rounded-rgControl border border-rg-border2 bg-rg-card2 px-3"
        >
            <label for="comment-body" class="sr-only">Comment</label>

            <textarea
                id="comment-body"
                name="body"
                wire:model="body"
                rows="1"
                maxlength="1000"
                placeholder="Add a comment..."
                @class([
                    'min-h-[32px] flex-1 resize-none bg-transparent py-1 text-[13px] leading-5 text-rg-text placeholder:text-rg-muted focus-visible:outline-none',
                    'text-rg-dangerText' => $errors->has('body'),
                ])
            ></textarea>

            @error('body')
                <p data-testid="comment-body-error" class="sr-only">
                    {{ $message }}
                </p>
            @enderror

            <x-ui.button
                type="submit"
                size="sm"
                wire:loading.attr="disabled"
                wire:target="submit"
            >
                <span wire:loading.remove wire:target="submit">Post</span>
                <span wire:loading wire:target="submit">Posting...</span>
            </x-ui.button>
        </form>
    @else
        <x-ui.empty-state
            title="Log in to comment"
            description="Sign in to join the conversation."
        />
    @endauth
</div>
