<section data-testid="notification-preferences-form">
    <header>
        <h2 class="text-base font-semibold text-rg-text">{{ __('ui.settings.notifications') }}</h2>
        <p class="mt-1 text-sm text-rg-muted">{{ __('ui.settings.notifications_description') }}</p>
    </header>

    <form wire:submit="save" class="mt-4 space-y-4">
        <div class="flex items-start gap-3">
            <input
                type="checkbox"
                wire:model="notify_followed_author_posts"
                id="notify_followed_author_posts"
                data-testid="notification-preference-followed-author-posts"
                class="mt-0.5 size-4 rounded border-rg-border2 bg-rg-card text-rg-accent focus:ring-2 focus:ring-rg-accent/25"
            >
            <div>
                <label for="notify_followed_author_posts" class="text-sm font-medium text-rg-text">
                    {{ __('follows.notifications.preference_label') }}
                </label>
                <p class="mt-0.5 text-xs text-rg-muted">{{ __('follows.notifications.preference_description') }}</p>
            </div>
        </div>

        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
            {{ __('ui.actions.save') }}
        </x-ui.button>
    </form>
</section>
