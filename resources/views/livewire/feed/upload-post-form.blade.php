@inject('settingsManager', \App\Support\Settings\ProjectSettingsManager::class)
@php $uploadSettings = $settingsManager->current(); @endphp
<div data-testid="upload-post-form">
    <h2 class="sr-only">{{ $uploadSettings->uploadCtaLabel() }}</h2>

    <form
        wire:submit.prevent="submit"
        data-testid="upload-form"
        class="space-y-4"
        x-data="{
            imageTab: @if($importedImageUrl) 'url' @else 'file' @endif,
            previewUrl: @js($importedImageUrl ?? null),
            fileName: null,
            tagOpen: false,
            initTagOpen() {
                this.$watch('tagOpen', v => {
                    if (v) this.$nextTick(() => this.$refs.tagInput?.focus());
                });
            }
        }"
        x-init="initTagOpen()"
        x-on:mousedown.window="
            !$refs.tagBox?.contains($event.target) && (tagOpen = false)
        "
        x-on:keydown.escape.window.capture="
            if (tagOpen) { $event.stopImmediatePropagation(); tagOpen = false; }
        "
        x-on:upload-modal-opened.window="
            previewUrl = null;
            fileName = null;
            imageTab = 'file';
            tagOpen = false;
            if ($refs.image) $refs.image.value = '';
        "
    >

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
        <div>
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
                    wire:click="$set('image', null)"
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

        {{-- Source URL — hidden when image loaded from URL (already captured above) --}}
        @if($uploadSettings->featureFlag('allow_url_imports'))
        <div x-show="imageTab !== 'url'">
        @endif
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
        @if($uploadSettings->featureFlag('allow_url_imports'))
        </div>
        @endif

        {{-- Tags — combobox with removable pills --}}
        <div>
            <x-input-label :value="__('ui.upload.tags')" />

            <div
                class="relative mt-1"
                data-testid="upload-tags"
                x-ref="tagBox"
            >
                {{-- Trigger / selected pills + search input --}}
                <div
                    class="flex min-h-[40px] cursor-text flex-wrap items-center gap-1.5 rounded-rgControl border border-rg-border2 bg-rg-card px-2.5 py-1.5 transition focus-within:border-rg-accent focus-within:ring-2 focus-within:ring-rg-accent/25"
                    x-on:click="tagOpen = true"
                    data-testid="upload-tag-field"
                >
                    {{-- Selected pills --}}
                    @foreach($selectedTags as $tag)
                        <span
                            class="inline-flex items-center gap-1 rounded-rgPill border border-rg-accentBorder bg-rg-accentSoft px-2 py-0.5 text-xs font-semibold text-rg-accent2"
                            data-testid="upload-selected-tag-{{ $tag['id'] }}"
                        >
                            {{ $tag['name'] }}
                            <button
                                type="button"
                                wire:click.stop="toggleTag({{ $tag['id'] }})"
                                class="cursor-pointer text-rg-accent2 hover:text-rg-accent focus-visible:outline-none"
                                aria-label="{{ __('ui.a11y.remove_tag', ['name' => $tag['name']]) }}"
                            >
                                <x-ui.icon name="x" class="size-3" />
                            </button>
                        </span>
                    @endforeach

                    {{-- Search input --}}
                    <input
                        type="text"
                        x-ref="tagInput"
                        wire:model.live.debounce.200ms="tagSearch"
                        x-on:focus="tagOpen = true"
                        :placeholder="@js(count($tagIds) === 0 ? __('ui.upload.tags_search') : '')"
                        class="h-7 min-w-[80px] flex-1 appearance-none border-0 bg-transparent p-0 text-[13px] text-rg-text placeholder-rg-muted outline-none focus:border-0 focus:outline-none focus:ring-0"
                        data-testid="upload-tag-search"
                        autocomplete="off"
                        role="combobox"
                        aria-autocomplete="list"
                        aria-controls="upload-tag-listbox"
                        :aria-expanded="tagOpen ? 'true' : 'false'"
                    />
                </div>

                {{-- Dropdown --}}
                <div
                    x-cloak
                    x-show="tagOpen"
                    class="absolute left-0 right-0 z-30 mt-1 max-h-52 overflow-y-auto rounded-rgControl border border-rg-border bg-rg-card2 p-1 shadow-rgDropdown"
                    data-testid="upload-tag-menu"
                    role="listbox"
                    id="upload-tag-listbox"
                >
                    @forelse($unselectedTags as $tag)
                        <button
                            type="button"
                            wire:click="toggleTag({{ $tag['id'] }})"
                            class="flex w-full cursor-pointer items-center rounded-rgSm px-3 py-1.5 text-left text-[13px] text-rg-text2 transition hover:bg-rg-card hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
                            data-testid="upload-tag-{{ $tag['id'] }}"
                            role="option"
                            id="upload-tag-option-{{ $tag['id'] }}"
                            aria-selected="false"
                        >
                            {{ $tag['name'] }}
                        </button>
                    @empty
                        <span class="block px-3 py-2 text-sm text-rg-muted">
                            {{ count($tagIds) > 0 ? __('ui.upload.tags_no_match') : __('ui.upload.tags_no_match') }}
                        </span>
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
