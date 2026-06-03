<div class="inline-flex items-center gap-2" wire:click.stop wire:keydown.stop>
    @if($saved)
        <button
            type="button"
            wire:click="toggle"
            wire:loading.attr="disabled"
            wire:target="toggle"
            class="inline-flex cursor-pointer items-center bg-transparent p-0 text-rg-accent transition hover:text-rg-accent2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent disabled:cursor-not-allowed disabled:opacity-60 [&_svg]:fill-current"
            data-testid="save-post-button"
            aria-pressed="true"
            aria-label="Saved"
            title="Saved"
        >
            <x-ui.icon name="bookmark" class="size-4" />
        </button>
    @else
        <x-ui.action-button
            icon="bookmark"
            wire:click="toggle"
            wire:loading.attr="disabled"
            wire:target="toggle"
            data-testid="save-post-button"
            aria-pressed="false"
            aria-label="Save"
            title="Save"
        >
            Save
        </x-ui.action-button>
    @endif

    @if($message && ! in_array($message, ['Saved', 'Removed'], true))
        <span class="text-xs text-rg-muted" role="status">{{ $message }}</span>
    @endif
</div>
