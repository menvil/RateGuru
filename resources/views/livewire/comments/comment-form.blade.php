<div>
    @auth
        <form data-testid="comment-form" wire:submit.prevent="submit" class="space-y-2">
            <label for="comment-body" class="block text-xs font-semibold uppercase tracking-wide text-rg-muted">
                Comment
            </label>

            <x-ui.textarea
                id="comment-body"
                name="body"
                wire:model="body"
                rows="3"
                maxlength="1000"
                placeholder="Write a comment..."
                :error="$errors->has('body')"
            />

            <div class="flex items-center justify-between">
                <p class="text-xs text-rg-muted">1000 characters max</p>

                <x-ui.button
                    type="submit"
                    size="sm"
                    wire:loading.attr="disabled"
                    wire:target="submit"
                >
                    <span wire:loading.remove wire:target="submit">Post comment</span>
                    <span wire:loading wire:target="submit">Posting...</span>
                </x-ui.button>
            </div>
        </form>
    @else
        <x-ui.empty-state
            title="Log in to comment"
            description="Sign in to join the conversation."
        />
    @endauth
</div>
