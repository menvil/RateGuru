<div>
    <x-ui.input
        name="search"
        type="search"
        placeholder="Search dishes..."
        :value="$search"
        wire:model="search"
        data-testid="search-input"
    />
</div>
