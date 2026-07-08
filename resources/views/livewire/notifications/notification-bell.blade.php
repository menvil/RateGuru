<div>
    @if(auth()->check())
        <div
            x-data="{ open: false }"
            data-testid="notification-bell"
            class="relative inline-flex items-center"
            @keydown.escape.window="open = false"
            @close-notification-menu.window="open = false"
        >
            <button
                type="button"
                aria-label="{{ __('ui.notifications.title') }}"
                @click="$dispatch('close-header-user-menu'); open = ! open"
                class="relative grid size-9 cursor-pointer place-items-center rounded-rgControl border border-rg-border bg-rg-card text-rg-text2 transition hover:bg-rg-card2 hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
            >
                <x-ui.icon name="bell" class="size-4" data-testid="notification-bell-icon" />

                @if($this->unreadCount > 0)
                    <span
                        data-testid="notification-unread-count"
                        class="absolute -right-1 -top-1 inline-flex min-w-5 items-center justify-center rounded-rgPill bg-rg-accent px-1.5 py-0.5 text-[11px] font-bold text-rg-onAccent"
                    >
                        {{ $this->unreadCount }}
                    </span>
                @endif
            </button>

            <div
                x-show="open"
                x-cloak
                data-testid="notifications-dropdown"
                class="fixed inset-x-4 top-[68px] z-40 overflow-hidden rounded-rgCard border border-rg-border bg-rg-card shadow-rgPopover sm:absolute sm:inset-x-auto sm:right-0 sm:top-11 sm:w-72 sm:max-w-[calc(100vw-2rem)]"
            >
                <div class="border-b border-rg-border px-4 py-3 text-sm font-semibold text-rg-text">
                    {{ __('ui.notifications.title') }}
                </div>

                <div class="max-h-80 overflow-y-auto break-words">
                    @forelse($this->notifications as $notification)
                        <div
                            data-testid="notification-item"
                            class="block border-b border-rg-border px-4 py-3 text-sm transition last:border-b-0 hover:bg-rg-card2 {{ $notification->read_at ? 'opacity-60' : '' }}"
                        >
                            <a href="{{ $notification->data['url'] ?? '#' }}" class="block">
                                <span class="block break-words font-medium text-rg-text">
                                    {{ $notification->data['message'] ?? __('ui.notifications.fallback_message') }}
                                </span>
                            </a>

                            <time class="mt-1 block text-xs text-rg-muted">
                                {{ $notification->created_at->diffForHumans() }}
                            </time>

                            @if($notification->read_at === null)
                                <button
                                    type="button"
                                    wire:click.prevent="markAsRead('{{ $notification->id }}')"
                                    data-testid="mark-notification-read"
                                    class="mt-2 cursor-pointer text-xs font-semibold text-rg-accent2 transition hover:text-rg-accent"
                                >
                                    {{ __('ui.notifications.mark_as_read') }}
                                </button>
                            @endif
                        </div>
                    @empty
                        <div class="px-4 py-6 text-center text-sm text-rg-muted">
                            {{ __('ui.notifications.empty') }}
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    @endif
</div>
