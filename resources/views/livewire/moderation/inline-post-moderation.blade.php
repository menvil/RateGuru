<div data-testid="inline-post-moderation">
    @if ($this->canModerate)
        <div data-testid="inline-post-moderation-panel" class="mt-3 rounded-md border border-rg-border2 bg-rg-card2 p-3">
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
                @endif

                @if ($post->status === \App\Enums\PostStatus::Published)
                    <x-ui.button
                        type="button"
                        variant="danger"
                        wire:click="hide"
                        data-testid="moderation-hide"
                    >
                        Hide
                    </x-ui.button>
                @endif
            </div>
        </div>
    @endif
</div>
