<div class="inline-flex items-center gap-2" wire:click.stop wire:keydown.stop>
    <x-ui.action-button
        icon="bookmark"
        wire:click="toggle"
        wire:loading.attr="disabled"
        wire:target="toggle"
        class="{{ $saved ? 'text-rg-accent hover:text-rg-accent2' : '' }}"
        data-testid="save-post-button"
        aria-pressed="{{ $saved ? 'true' : 'false' }}"
    >
        {{ $saved ? 'Saved' : 'Save' }}
    </x-ui.action-button>

    @if($message)
        <span class="text-xs text-rg-muted" role="status">{{ $message }}</span>
    @endif
</div>
