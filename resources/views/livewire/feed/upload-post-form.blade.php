@php use App\Enums\OriginType; use App\Enums\CuisineType; @endphp
<div data-testid="upload-post-form">
    <h2 class="sr-only">Create post</h2>

    <form wire:submit.prevent="submit" class="space-y-4">
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

        <div x-data="{ previewUrl: null }">
            <x-input-label for="image" value="Image" />
            <input
                id="image"
                name="image"
                type="file"
                accept="image/*"
                wire:model="image"
                class="mt-1 block w-full text-sm text-rg-text2 file:mr-3 file:rounded-rgControl file:border-0 file:bg-rg-card2 file:px-3 file:py-1.5 file:text-xs file:font-medium file:text-rg-text file:transition file:hover:bg-rg-card"
                x-on:change="
                    const file = $event.target.files[0];
                    if (!file) { previewUrl = null; return; }
                    const reader = new FileReader();
                    reader.onload = e => previewUrl = e.target.result;
                    reader.readAsDataURL(file);
                "
            />
            <div class="mt-2">
                <template x-if="previewUrl">
                    <img :src="previewUrl" alt="Selected image preview" class="max-h-48 w-full rounded-rgCard object-cover" />
                </template>
                <div x-show="!previewUrl">
                    <x-ui.image-placeholder label="Image preview" ratio="video" />
                </div>
            </div>
            <div data-testid="field-error-image" class="mt-1">
                <x-input-error :messages="$errors->get('image')" />
            </div>
        </div>

        <div>
            <x-input-label for="source_url" value="Source URL" />
            <x-ui.input
                id="source_url"
                name="source_url"
                type="url"
                wire:model.defer="sourceUrl"
                placeholder="https://example.com/original"
                class="mt-1"
            />
            <div data-testid="field-error-source-url" class="mt-1">
                <x-input-error :messages="$errors->get('sourceUrl')" />
            </div>
        </div>

        <div>
            <x-input-label for="originTruth" value="Origin" />
            <select
                id="originTruth"
                name="originTruth"
                wire:model.defer="originTruth"
                class="mt-1 block h-10 w-full rounded-rgControl border border-rg-border2 bg-rg-card2 px-3 text-[13.5px] text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[rgba(168,85,247,0.25)]"
            >
                <option value="{{ OriginType::Unknown->value }}">Keep unknown</option>
                <option value="{{ OriginType::Homemade->value }}">Homemade</option>
                <option value="{{ OriginType::Restaurant->value }}">Restaurant</option>
            </select>
            <div data-testid="field-error-origin-truth" class="mt-1">
                <x-input-error :messages="$errors->get('originTruth')" />
            </div>
        </div>

        <div>
            <x-input-label for="cuisineTruth" value="Cuisine" />
            <select
                id="cuisineTruth"
                name="cuisineTruth"
                wire:model.defer="cuisineTruth"
                class="mt-1 block h-10 w-full rounded-rgControl border border-rg-border2 bg-rg-card2 px-3 text-[13.5px] text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[rgba(168,85,247,0.25)]"
            >
                <option value="{{ CuisineType::Unknown->value }}">Keep unknown</option>
                <option value="{{ CuisineType::Italian->value }}">Italian</option>
                <option value="{{ CuisineType::Asian->value }}">Asian</option>
                <option value="{{ CuisineType::American->value }}">American</option>
                <option value="{{ CuisineType::Mexican->value }}">Mexican</option>
                <option value="{{ CuisineType::Other->value }}">Other</option>
            </select>
            <div data-testid="field-error-cuisine-truth" class="mt-1">
                <x-input-error :messages="$errors->get('cuisineTruth')" />
            </div>
        </div>

        <div>
            <x-input-label value="Tags" />
            <div class="mt-1 rounded-rgControl border border-rg-border2 bg-rg-card2 px-3 py-2 text-sm text-rg-muted">
                Tag selection coming soon
            </div>
        </div>

        @if($submitError)
            <x-ui.error-message
                title="Something went wrong"
                :message="$submitError"
            />
        @endif

        <div class="flex justify-end">
            <x-ui.button type="submit" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="submit">Create post</span>
                <span wire:loading wire:target="submit">Uploading...</span>
            </x-ui.button>
        </div>
    </form>
</div>
