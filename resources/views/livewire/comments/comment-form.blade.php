<div>
    @auth
        <form
            data-testid="comment-form"
            wire:submit.prevent="submit"
            class="flex min-h-[50px] items-center gap-2.5 rounded-[10px] border border-rg-border2 bg-rg-card2 py-2 pl-3.5 pr-2"
        >
            <label for="comment-body" class="sr-only">Comment</label>

            <input
                id="comment-body"
                name="body"
                type="text"
                wire:model="body"
                maxlength="1000"
                placeholder="Add a comment..."
                @class([
                    'h-8 flex-1 border-0 bg-transparent p-0 text-[13.5px] text-rg-text outline-none ring-0 placeholder:text-rg-muted focus:border-0 focus:outline-none focus:ring-0 focus-visible:border-0 focus-visible:outline-none focus-visible:ring-0',
                    'text-rg-dangerText' => $errors->has('body'),
                ])
            >

            @error('body')
                <p data-testid="comment-body-error" class="sr-only">
                    {{ $message }}
                </p>
            @enderror

            <x-ui.button
                type="submit"
                size="sm"
                class="h-8 rounded-rgSm px-4 text-[12.5px]"
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
