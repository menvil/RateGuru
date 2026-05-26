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
                    'h-8 flex-1 bg-transparent text-[13.5px] text-rg-text placeholder:text-rg-muted focus-visible:outline-none',
                    'text-rg-dangerText' => $errors->has('body'),
                ])
            >

            @error('body')
                <p data-testid="comment-body-error" class="sr-only">
                    {{ $message }}
                </p>
            @enderror

            <button
                type="button"
                aria-label="Attach image"
                class="grid size-8 cursor-pointer place-items-center rounded-rgSm bg-transparent p-1 text-rg-muted transition hover:bg-rg-card hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
            >
                <x-ui.icon name="image" class="size-[17px]" />
            </button>

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
