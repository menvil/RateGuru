<div class="inline-flex items-center gap-2" wire:click.stop wire:keydown.stop>
    @if($this->isFeatureEnabled() && !$this->isSelf())
        @if($isFollowing)
            <button
                type="button"
                wire:click="toggle"
                wire:loading.attr="disabled"
                wire:target="toggle"
                class="inline-flex h-8 cursor-pointer items-center justify-center gap-1.5 rounded-rgControl border border-rg-accent bg-rg-accent px-3 text-xs font-semibold text-rg-onAccent transition-colors hover:bg-rg-accentHover hover:border-rg-accentHover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent focus-visible:ring-offset-2 focus-visible:ring-offset-rg-bg disabled:cursor-not-allowed disabled:opacity-60"
                data-testid="follow-button"
                aria-pressed="true"
                aria-label="{{ __('follows.following') }}"
                title="{{ __('follows.unfollow') }}"
            >
                {{ __('follows.following') }}
            </button>
        @else
            <button
                type="button"
                wire:click="toggle"
                wire:loading.attr="disabled"
                wire:target="toggle"
                class="inline-flex h-8 cursor-pointer items-center justify-center gap-1.5 rounded-rgControl border border-rg-border2 bg-rg-card px-3 text-xs font-semibold text-rg-text2 transition-colors hover:bg-rg-card2 hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent focus-visible:ring-offset-2 focus-visible:ring-offset-rg-bg disabled:cursor-not-allowed disabled:opacity-60"
                data-testid="follow-button"
                aria-pressed="false"
                aria-label="{{ __('follows.follow') }}"
                title="{{ __('follows.follow') }}"
            >
                {{ __('follows.follow') }}
            </button>
        @endif

        @if($message)
            <span class="text-xs text-rg-muted" role="status" data-testid="follow-button-message">{{ $message }}</span>
        @endif
    @endif
</div>
