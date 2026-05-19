<div>
    @auth
        <form data-testid="comment-form" wire:submit.prevent="submit">
            <p class="text-sm text-rg-muted">Comment form</p>
        </form>
    @else
        <x-ui.empty-state
            title="Log in to comment"
            description="Sign in to join the conversation."
        />
    @endauth
</div>
