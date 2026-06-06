@php use App\Enums\OriginType; use App\Enums\CuisineType; @endphp
@inject('settingsManager', \App\Support\Settings\ProjectSettingsManager::class)
@php $uploadSettings = $settingsManager->current(); @endphp
<div data-testid="upload-post-form">
    <h2 class="sr-only">{{ $uploadSettings->uploadCtaLabel() }}</h2>

    <form wire:submit.prevent="submit" class="space-y-4">
        <div>
            <x-input-label for="title" value="Title" />
            <x-ui.input
                id="title"
                name="title"
                wire:model.defer="title"
                placeholder="Title"
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

        <div x-data="{ previewUrl: null, fileName: null }">
            <x-input-label for="image" value="Image" />
            <label
                for="image"
                class="mt-1 flex cursor-pointer flex-col items-center justify-center rounded-rgCard border border-dashed border-rg-border2 bg-rg-card2 px-4 py-6 text-center transition hover:border-rg-accentBorder hover:bg-rg-card"
                data-testid="upload-image-dropzone"
                x-on:dragover.prevent
                x-on:drop.prevent="
                    const file = $event.dataTransfer.files[0];
                    if (!file) { previewUrl = null; fileName = null; return; }
                    $refs.image.files = $event.dataTransfer.files;
                    $refs.image.dispatchEvent(new Event('change', { bubbles: true }));
                "
            >
                <input
                    id="image"
                    name="image"
                    type="file"
                    accept="image/*"
                    wire:model="image"
                    x-ref="image"
                    class="sr-only"
                    x-on:change="
                        const file = $event.target.files[0];
                        if (!file) { previewUrl = null; fileName = null; return; }
                        fileName = file.name;
                        const reader = new FileReader();
                        reader.onload = e => previewUrl = e.target.result;
                        reader.readAsDataURL(file);
                    "
                />

                <template x-if="previewUrl">
                    <img :src="previewUrl" alt="Selected image preview" class="max-h-56 w-full rounded-rgMedia object-contain" />
                </template>

                <div x-show="!previewUrl" class="flex flex-col items-center gap-2">
                    <span class="grid size-10 place-items-center rounded-rgPill border border-rg-border2 bg-rg-card text-rg-muted">
                        <x-ui.icon name="upload" class="size-5" />
                    </span>
                    <div>
                        <p class="text-sm font-semibold text-rg-text">Choose a file or drag & drop it here</p>
                        <p class="mt-1 text-xs text-rg-muted">JPEG, PNG, GIF up to 5MB</p>
                    </div>
                    <span class="mt-1 inline-flex h-9 items-center rounded-rgControl border border-rg-border2 bg-rg-card px-4 text-[13px] font-semibold text-rg-text">
                        Browse File
                    </span>
                </div>

                <p x-show="fileName" x-text="fileName" class="mt-2 text-xs text-rg-muted"></p>
            </label>
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
            <x-input-label for="originTruth" value="Source" />
            <select
                id="originTruth"
                name="originTruth"
                wire:model.defer="originTruth"
                class="mt-1 block h-10 w-full rounded-rgControl border border-rg-border2 bg-rg-card2 px-3 text-[13.5px] text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent/25"
            >
                <option value="{{ OriginType::Unknown->value }}">Keep unknown</option>
                <option value="{{ OriginType::Homemade->value }}">Source A</option>
                <option value="{{ OriginType::Restaurant->value }}">Source B</option>
            </select>
            <div data-testid="field-error-origin-truth" class="mt-1">
                <x-input-error :messages="$errors->get('originTruth')" />
            </div>
        </div>

        <div>
            <x-input-label for="cuisineTruth" value="Category" />
            <select
                id="cuisineTruth"
                name="cuisineTruth"
                wire:model.defer="cuisineTruth"
                class="mt-1 block h-10 w-full rounded-rgControl border border-rg-border2 bg-rg-card2 px-3 text-[13.5px] text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent/25"
            >
                <option value="{{ CuisineType::Unknown->value }}">Keep unknown</option>
                <option value="{{ CuisineType::Italian->value }}">Category A</option>
                <option value="{{ CuisineType::Asian->value }}">Category B</option>
                <option value="{{ CuisineType::American->value }}">Category C</option>
                <option value="{{ CuisineType::Mexican->value }}">Category D</option>
                <option value="{{ CuisineType::Other->value }}">Other</option>
            </select>
            <div data-testid="field-error-cuisine-truth" class="mt-1">
                <x-input-error :messages="$errors->get('cuisineTruth')" />
            </div>
        </div>

        <div>
            <x-input-label value="Tags" />
            <div
                class="mt-1"
                data-testid="upload-tags"
                x-data="{ open: false }"
                x-on:click.outside="open = false"
            >
                <div class="relative">
                    <x-ui.input
                        name="tagSearch"
                        type="search"
                        wire:model.live.debounce.250ms="tagSearch"
                        x-on:focus="open = true"
                        x-on:input="open = true"
                        placeholder="Search or select tags"
                        data-testid="upload-tag-search"
                        role="combobox"
                        aria-autocomplete="list"
                        aria-controls="upload-tag-listbox"
                        x-bind:aria-expanded="open"
                    />

                    <div
                        id="upload-tag-listbox"
                        role="listbox"
                        aria-label="Available tags"
                        aria-multiselectable="true"
                        x-cloak
                        x-show="open"
                        class="absolute left-0 right-0 z-30 mt-2 max-h-60 overflow-y-auto rounded-rgControl border border-rg-border bg-rg-card2 p-1 shadow-rgDropdown"
                        data-testid="upload-tag-menu"
                    >
                        @forelse($filteredTags as $tag)
                            <button
                                type="button"
                                wire:click="toggleTag({{ $tag['id'] }})"
                                class="flex w-full cursor-pointer items-center gap-2 rounded-rgSm px-3 py-2 text-left text-[13px] font-semibold text-rg-text2 transition hover:bg-rg-card hover:text-rg-text"
                                data-testid="upload-tag-{{ $tag['id'] }}"
                                id="upload-tag-option-{{ $tag['id'] }}"
                                role="option"
                                aria-selected="{{ in_array($tag['id'], $tagIds, true) ? 'true' : 'false' }}"
                            >
                                <input
                                    type="checkbox"
                                    @checked(in_array($tag['id'], $tagIds, true))
                                    class="size-3.5 rounded border-rg-border2 bg-rg-card text-rg-accent focus:ring-rg-accent"
                                    tabindex="-1"
                                    readonly
                                >
                                {{ $tag['name'] }}
                            </button>
                        @empty
                            <span class="block px-3 py-2 text-sm text-rg-muted">No matching tags.</span>
                        @endforelse
                    </div>
                </div>

                @if($selectedTags !== [])
                    <div class="mt-2 flex flex-wrap gap-2" data-testid="upload-selected-tags">
                        @foreach($selectedTags as $tag)
                            <button
                                type="button"
                                wire:click="toggleTag({{ $tag['id'] }})"
                                class="inline-flex cursor-pointer items-center gap-1.5 rounded-rgPill border border-rg-accentBorder bg-rg-accentSoft px-2.5 py-1 text-xs font-semibold text-rg-accent2"
                            >
                                {{ $tag['name'] }}
                                <x-ui.icon name="x" class="size-3" />
                            </button>
                        @endforeach
                    </div>
                @endif

                @if($popularTags !== [])
                    <div class="mt-2">
                        <p class="text-xs font-semibold text-rg-muted">Popular tags</p>
                        <div class="mt-1.5 flex flex-wrap gap-2" data-testid="upload-popular-tags">
                            @foreach($popularTags as $tag)
                                <button
                                    type="button"
                                    wire:click="toggleTag({{ $tag['id'] }})"
                                    class="inline-flex cursor-pointer items-center rounded-rgPill border border-rg-border2 bg-rg-card px-2.5 py-1 text-xs font-semibold text-rg-text2 transition hover:border-rg-accentBorder hover:text-rg-text"
                                >
                                    {{ $tag['name'] }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
            <div data-testid="field-error-tags" class="mt-1">
                <x-input-error :messages="$errors->get('tagIds')" />
                <x-input-error :messages="collect($errors->get('tagIds.*'))->flatten()->all()" />
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
