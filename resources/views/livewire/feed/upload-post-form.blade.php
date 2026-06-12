@inject('settingsManager', \App\Support\Settings\ProjectSettingsManager::class)
@php $uploadSettings = $settingsManager->current(); @endphp
<div data-testid="upload-post-form">
    <h2 class="sr-only">{{ $uploadSettings->uploadCtaLabel() }}</h2>

    <form wire:submit.prevent="submit" data-testid="upload-form" class="space-y-4">

        {{-- Title --}}
        <div>
            <x-input-label for="title" :value="__('ui.upload.title')" />
            <x-ui.input
                id="title"
                name="title"
                wire:model.defer="title"
                :placeholder="__('ui.upload.title_placeholder')"
                class="mt-1"
            />
            <div data-testid="field-error-title" class="mt-1">
                <x-input-error :messages="$errors->get('title')" />
            </div>
        </div>

        {{-- Description --}}
        <div>
            <x-input-label for="description" :value="__('ui.upload.description')" />
            <x-ui.textarea
                id="description"
                name="description"
                wire:model.defer="description"
                rows="3"
                :placeholder="__('ui.upload.description_placeholder')"
                class="mt-1"
            />
            <div data-testid="field-error-description" class="mt-1">
                <x-input-error :messages="$errors->get('description')" />
            </div>
        </div>

        {{-- Image with tabs --}}
        <div
            x-data="{
                imageTab: @if($importedImageUrl) 'url' @else 'file' @endif,
                previewUrl: @if($importedImageUrl) '{{ $importedImageUrl }}' @else null @endif,
                fileName: null
            }"
        >
            <x-input-label :value="__('ui.upload.image')" />

            @if($uploadSettings->featureFlag('allow_url_imports'))
            <div class="mt-1 flex gap-1 border-b border-rg-border pb-2">
                <button
                    type="button"
                    x-on:click="imageTab = 'file'"
                    data-testid="image-tab-file"
                    :class="imageTab === 'file'
                        ? 'bg-rg-accent text-rg-onAccent'
                        : 'text-rg-muted hover:text-rg-text'"
                    class="rounded-rgSm px-3 py-1 text-xs font-medium transition"
                >{{ __('ui.upload.image_tab_file') }}</button>
                <button
                    type="button"
                    x-on:click="imageTab = 'url'"
                    data-testid="image-tab-url"
                    :class="imageTab === 'url'
                        ? 'bg-rg-accent text-rg-onAccent'
                        : 'text-rg-muted hover:text-rg-text'"
                    class="rounded-rgSm px-3 py-1 text-xs font-medium transition"
                >{{ __('ui.upload.image_tab_url') }}</button>
            </div>
            @endif

            {{-- File upload tab --}}
            <div x-show="imageTab === 'file'" class="mt-2">
                <label
                    for="image"
                    class="flex cursor-pointer flex-col items-center justify-center rounded-rgCard border border-dashed border-rg-border2 bg-rg-card2 px-4 py-6 text-center transition hover:border-rg-accentBorder hover:bg-rg-card"
                    data-testid="upload-image-dropzone"
                    x-on:dragover.prevent
                    x-on:drop.prevent="
                        const file = $event.dataTransfer.files[0];
                        if (!file) return;
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

                    <template x-if="previewUrl && imageTab === 'file'">
                        <img :src="previewUrl" alt="Selected image preview" class="max-h-56 w-full rounded-rgMedia object-contain" />
                    </template>

                    <div x-show="!previewUrl || imageTab !== 'file'" class="flex flex-col items-center gap-2">
                        <span class="grid size-10 place-items-center rounded-rgPill border border-rg-border2 bg-rg-card text-rg-muted">
                            <x-ui.icon name="upload" class="size-5" />
                        </span>
                        <div>
                            <p class="text-sm font-semibold text-rg-text">{{ __('ui.upload.image_drop_text') }}</p>
                            <p class="mt-1 text-xs text-rg-muted">{{ __('ui.upload.image_drop_hint') }}</p>
                        </div>
                        <span class="mt-1 inline-flex h-9 items-center rounded-rgControl border border-rg-border2 bg-rg-card px-4 text-[13px] font-semibold text-rg-text">
                            {{ __('ui.upload.image_browse') }}
                        </span>
                    </div>

                    <p x-show="fileName && imageTab === 'file'" x-text="fileName" class="mt-2 text-xs text-rg-muted"></p>
                </label>
            </div>

            {{-- URL tab --}}
            @if($uploadSettings->featureFlag('allow_url_imports'))
            <div x-show="imageTab === 'url'" class="mt-2 space-y-2">
                <x-ui.input
                    name="importedImageUrl"
                    type="url"
                    wire:model.defer="importedImageUrl"
                    :placeholder="__('ui.upload.image_url_placeholder')"
                    data-testid="upload-image-url-input"
                    x-on:input="previewUrl = $event.target.value || null"
                />
                <template x-if="previewUrl && imageTab === 'url'">
                    <img :src="previewUrl" alt="Image preview" class="max-h-48 w-full rounded-rgMedia object-contain" />
                </template>
            </div>
            @endif

            <div data-testid="field-error-image" class="mt-1">
                <x-input-error :messages="$errors->get('image')" />
            </div>
        </div>

        {{-- Source URL --}}
        <div>
            <x-input-label for="source_url" :value="__('ui.upload.source_url')" />
            <x-ui.input
                id="source_url"
                name="source_url"
                type="url"
                wire:model.defer="sourceUrl"
                :placeholder="__('ui.upload.source_url_placeholder')"
                class="mt-1"
            />
            <div data-testid="field-error-source-url" class="mt-1">
                <x-input-error :messages="$errors->get('sourceUrl')" />
            </div>
        </div>

        {{-- Tags --}}
        <div>
            <x-input-label :value="__('ui.upload.tags')" />
            <div class="mt-1" data-testid="upload-tags" x-data="{ tagSearch: '' }">

                {{-- Search --}}
                <input
                    type="search"
                    x-model="tagSearch"
                    :placeholder="'{{ __('ui.upload.tags_search') }}'"
                    class="rg-search-input mb-2 h-9 w-full rounded-rgControl border border-rg-border2 bg-rg-card px-3 text-[13px] text-rg-text placeholder-rg-muted focus-visible:border-rg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent/25"
                    data-testid="upload-tag-search"
                />

                {{-- Tag pills --}}
                <div class="flex flex-wrap gap-1.5" data-testid="upload-tag-pills">
                    @forelse($filteredTags as $tag)
                        <button
                            type="button"
                            wire:click="toggleTag({{ $tag['id'] }})"
                            x-show="tagSearch === '' || '{{ mb_strtolower($tag['name']) }}'.includes(tagSearch.toLowerCase())"
                            data-testid="upload-tag-{{ $tag['id'] }}"
                            @class([
                                'inline-flex cursor-pointer items-center rounded-rgPill border px-2.5 py-1 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent',
                                'border-rg-accentBorder bg-rg-accentSoft text-rg-accent2' => in_array($tag['id'], $tagIds, true),
                                'border-rg-border2 bg-rg-card text-rg-text2 hover:border-rg-accentBorder hover:text-rg-text' => !in_array($tag['id'], $tagIds, true),
                            ])
                        >
                            @if(in_array($tag['id'], $tagIds, true))
                                <x-ui.icon name="check" class="mr-1 size-3" />
                            @endif
                            {{ $tag['name'] }}
                        </button>
                    @empty
                        <span class="text-sm text-rg-muted">{{ __('ui.upload.tags_no_match') }}</span>
                    @endforelse
                </div>

            </div>
            <div data-testid="field-error-tags" class="mt-1">
                <x-input-error :messages="$errors->get('tagIds')" />
                <x-input-error :messages="collect($errors->get('tagIds.*'))->flatten()->all()" />
            </div>
        </div>

        @if($submitError)
            <x-ui.error-message
                :title="__('ui.upload.error_generic')"
                :message="$submitError"
            />
        @endif

        <div class="flex justify-end">
            <x-ui.button type="submit" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="submit">{{ __('ui.upload.submit') }}</span>
                <span wire:loading wire:target="submit">{{ __('ui.upload.submitting') }}</span>
            </x-ui.button>
        </div>
    </form>
</div>
