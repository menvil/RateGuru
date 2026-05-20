<div data-testid="inline-post-moderation">
    @if ($this->canModerate)
        <div
            x-data="{ confirmHideOpen: false }"
            data-testid="inline-post-moderation-panel"
            class="mt-3 rounded-md border border-rg-border2 bg-rg-card2 p-3"
        >
            <x-ui.badge>Moderator</x-ui.badge>

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
</div>
