<div data-testid="inline-post-moderation">
    @if ($this->canModerate)
        @if ($variant === 'menu')
            <div x-data="{ compactHideOpen: false }" data-testid="inline-post-moderation-menu" class="border-t border-rg-border2 pt-1">
                @if ($this->adminPostUrl)
                    <a
                        href="{{ $this->adminPostUrl }}"
                        target="_blank"
                        rel="noopener"
                        class="flex w-full cursor-pointer items-center rounded-rgSm px-3 py-2 text-sm font-semibold text-rg-text2 transition hover:bg-rg-card hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
                    >
                        Open in admin
                    </a>
                @endif

                @if ($post->status === \App\Enums\PostStatus::Pending)
                    <button
                        type="button"
                        wire:click="approve"
                        data-testid="moderation-approve"
                        class="flex w-full cursor-pointer items-center rounded-rgSm px-3 py-2 text-left text-sm font-semibold text-rg-text2 transition hover:bg-rg-card hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
                    >
                        Approve
                    </button>

                    <button
                        type="button"
                        wire:click="reject"
                        data-testid="moderation-reject"
                        class="flex w-full cursor-pointer items-center rounded-rgSm px-3 py-2 text-left text-sm font-semibold text-rg-dangerText transition hover:bg-rg-dangerSoft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-dangerText"
                    >
                        Reject
                    </button>
                @endif

                @if ($post->status === \App\Enums\PostStatus::Published)
                    <button
                        type="button"
                        x-on:click="compactHideOpen = true"
                        data-testid="moderation-hide"
                        class="flex w-full cursor-pointer items-center rounded-rgSm px-3 py-2 text-left text-sm font-semibold text-rg-dangerText transition hover:bg-rg-dangerSoft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-dangerText"
                    >
                        Hide
                    </button>

                    <x-ui.modal title="Hide this post?" state="compactHideOpen">
                        <p class="text-sm text-rg-text2">
                            This will remove the post from public feeds.
                        </p>

                        <x-slot:footer>
                            <x-ui.button
                                type="button"
                                variant="secondary"
                                x-on:click="compactHideOpen = false"
                                data-testid="hide-confirmation-cancel"
                            >
                                Cancel
                            </x-ui.button>

                            <x-ui.button
                                type="button"
                                variant="danger"
                                wire:click="hide"
                                x-on:click="compactHideOpen = false"
                                data-testid="hide-confirmation-confirm"
                            >
                                Confirm hide
                            </x-ui.button>
                        </x-slot:footer>
                    </x-ui.modal>
                @endif

                @if ($post->status === \App\Enums\PostStatus::Hidden)
                    <button
                        type="button"
                        wire:click="restore"
                        data-testid="moderation-restore"
                        class="flex w-full cursor-pointer items-center rounded-rgSm px-3 py-2 text-left text-sm font-semibold text-rg-text2 transition hover:bg-rg-card hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
                    >
                        Restore
                    </button>
                @endif
            </div>
        @else
        <div
            x-data="{ confirmHideOpen: false }"
            data-testid="inline-post-moderation-panel"
            class="mt-3 rounded-md border border-rg-border2 bg-rg-card2 p-3"
        >
            <div class="flex items-center justify-between gap-2">
                <x-ui.badge>Moderator</x-ui.badge>

                <div data-testid="open-in-admin-link" class="text-xs">
                    @if ($this->adminPostUrl)
                        <a
                            href="{{ $this->adminPostUrl }}"
                            target="_blank"
                            rel="noopener"
                            class="text-rg-text2 hover:text-rg-text"
                        >
                            Open in admin
                        </a>
                    @else
                        <span class="text-rg-muted">
                            Open in admin
                        </span>
                    @endif
                </div>
            </div>

            @if ($error)
                <p data-testid="moderation-error" class="mt-2 text-xs text-rg-danger">
                    {{ $error }}
                </p>
            @endif

            @if ($success)
                <p data-testid="moderation-success" class="mt-2 text-xs text-rg-text2">
                    {{ $success }}
                </p>
            @endif

            <div class="mt-2">
                <label for="moderation-reason-{{ $post->id }}" class="block text-xs text-rg-text2">
                    Reason
                </label>
                <x-ui.textarea
                    name="moderation_reason"
                    id="moderation-reason-{{ $post->id }}"
                    rows="2"
                    maxlength="1000"
                    placeholder="Optional moderation note..."
                    wire:model.defer="reason"
                    data-testid="moderation-reason-input"
                />
            </div>

            <div class="mt-2 flex flex-wrap gap-2">
                @if ($post->status === \App\Enums\PostStatus::Pending)
                    <x-ui.button
                        type="button"
                        wire:click="approve"
                        data-testid="moderation-approve"
                    >
                        Approve
                    </x-ui.button>

                    <x-ui.button
                        type="button"
                        variant="danger"
                        wire:click="reject"
                        data-testid="moderation-reject"
                    >
                        Reject
                    </x-ui.button>
                @endif

                @if ($post->status === \App\Enums\PostStatus::Published)
                    <x-ui.button
                        type="button"
                        variant="danger"
                        x-on:click="confirmHideOpen = true"
                        data-testid="moderation-hide"
                    >
                        Hide
                    </x-ui.button>
                @endif

                @if ($post->status === \App\Enums\PostStatus::Hidden)
                    <x-ui.button
                        type="button"
                        wire:click="restore"
                        data-testid="moderation-restore"
                    >
                        Restore
                    </x-ui.button>
                @endif
            </div>

            @if ($post->status === \App\Enums\PostStatus::Published)
                <div data-testid="hide-confirmation-modal">
                    <x-ui.modal title="Hide this post?" state="confirmHideOpen">
                        <p class="text-sm text-rg-text2">
                            This will remove the post from public feeds.
                        </p>

                        <x-slot:footer>
                            <x-ui.button
                                type="button"
                                variant="secondary"
                                x-on:click="confirmHideOpen = false"
                                data-testid="hide-confirmation-cancel"
                            >
                                Cancel
                            </x-ui.button>

                            <x-ui.button
                                type="button"
                                variant="danger"
                                wire:click="hide"
                                x-on:click="confirmHideOpen = false"
                                data-testid="hide-confirmation-confirm"
                            >
                                Confirm hide
                            </x-ui.button>
                        </x-slot:footer>
                    </x-ui.modal>
                </div>
            @endif
        </div>
        @endif
    @endif
</div>
