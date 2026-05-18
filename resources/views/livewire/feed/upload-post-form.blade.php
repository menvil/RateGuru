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

    <div>
        <x-input-label for="image" value="Image" />
        <input
            id="image"
            name="image"
            type="file"
            accept="image/*"
            wire:model="image"
            class="mt-1 block w-full text-sm text-rg-text2 file:mr-3 file:rounded-rgControl file:border-0 file:bg-rg-card2 file:px-3 file:py-1.5 file:text-xs file:font-medium file:text-rg-text file:transition file:hover:bg-rg-card"
        />
        <div data-testid="field-error-image" class="mt-1">
            <x-input-error :messages="$errors->get('image')" />
        </div>
    </div>
</div>
