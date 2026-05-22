<div>
    @if(auth()->check())
        <div data-testid="notification-bell" class="inline-flex items-center">
            <button
                type="button"
                class="inline-flex h-9 items-center gap-2 rounded-rgControl border border-rg-border2 bg-rg-card px-3 text-xs font-semibold text-rg-text2 transition hover:bg-rg-card2 hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
            >
                Notifications

                @if($this->unreadCount > 0)
                    <span
                        data-testid="notification-unread-count"
                        class="inline-flex min-w-5 items-center justify-center rounded-rgPill bg-rg-accent px-1.5 py-0.5 text-[11px] font-bold text-white"
                    >
                        {{ $this->unreadCount }}
                    </span>
                @endif
            </button>
        </div>
    @endif
</div>
