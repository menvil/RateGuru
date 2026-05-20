<div data-testid="inline-post-moderation">
    @if ($this->canModerate)
        <div data-testid="inline-post-moderation-panel" class="mt-3 rounded-md border border-rg-border2 bg-rg-card2 p-3">
            <x-ui.badge>Moderator</x-ui.badge>
        </div>
    @endif
</div>
