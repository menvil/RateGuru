<div>
    <x-ui.input
        name="search"
        type="search"
        placeholder="Search posts..."
        :value="$search"
        wire:model.live.debounce.500ms="search"
        data-testid="search-input"
    />
</div>
