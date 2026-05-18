<div data-testid="upload-post-form" class="space-y-4">
    <h2 class="sr-only">Create post</h2>
    <div>
        <x-input-label for="title" value="Title" />
        <x-ui.input
            id="title"
            name="title"
            wire:model.defer="title"
            placeholder="Dish title"
            class="mt-1"
        />
        <div data-testid="field-error-title" class="mt-1">
            <x-input-error :messages="$errors->get('title')" />
        </div>
    </div>

    <div>
        <x-input-label for="description" value="Description" />
        <x-ui.textarea
            id="description"
            name="description"
            wire:model.defer="description"
            rows="4"
            placeholder="Optional details"
            class="mt-1"
        />
        <div data-testid="field-error-description" class="mt-1">
            <x-input-error :messages="$errors->get('description')" />
        </div>
    </div>
</div>
