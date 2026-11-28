@inject('settingsManager', \App\Support\Settings\ProjectSettingsManager::class)
@php $uploadSettings = $settingsManager->current(); @endphp
<div data-testid="upload-post-form">
    <h2 class="sr-only">{{ $uploadSettings->uploadCtaLabel() }}</h2>

    @if ($needsConfirmation)
        {{-- No form to fill in for nothing: an account that has not confirmed its address may not post yet. --}}
        <p role="status" data-testid="upload-confirmation-required" class="rounded-rgCard border border-rg-border bg-rg-surface px-5 py-4 text-sm text-rg-text">
            {{ __('ui.account_confirmation.required') }}
        </p>
    @else
    {{-- novalidate: a hidden address field the browser found wrong would stop the form without
         a word; the server checks every field and says what is wrong. --}}
    <form
        wire:submit.prevent="submit"
        novalidate
        data-testid="upload-form"
        class="space-y-4"
        x-data="{
            {{-- Which the post's picture comes from — a file or a link — set by whichever was given last:
                 one block takes both, and giving one lets the other go. --}}
            imageTab: @if($importedImageUrl) 'url' @else 'file' @endif,
            previewUrl: null,
            previewIsVideo: false,
            fileName: null,
            mediaError: null,
            uploadProgress: null,
            maxVideoSeconds: @js((int) config('uploads.videos.max_duration_seconds')),
            maxVideoBytes: @js((int) config('uploads.videos.max_kilobytes') * 1024),
            tagOpen: false,
            {{-- Which pick is current: every reset moves it on, so what an earlier pick still
                 had on its way — a video's metadata, a preview, its upload — lands nowhere. --}}
            pick: 0,
            clearMedia() {
                this.pick++;
                if (this.previewIsVideo && this.previewUrl) URL.revokeObjectURL(this.previewUrl);
                this.previewUrl = null;
                this.previewIsVideo = false;
                this.fileName = null;
                this.uploadProgress = null;
            },
            {{-- Both fields let go of what they held, here and on the server, before anything else:
                 what is shown is then what is posted, also when the new file is refused. --}}
            forgetFiles() {
                this.$wire.cancelUpload('image');
                this.$wire.cancelUpload('video');
                this.$wire.set('image', null, false);
                this.$wire.set('video', null, false);
            },
            {{-- A file given: the link lets go, here and on the server, so what is shown is what is posted. --}}
            useFile() {
                this.imageTab = 'file';
                this.$wire.importedImageUrl = null;
                this.$wire.set('linkPreview', null, false);
            },
            {{-- A link given: the file lets go — one still on its way too, or it would be posted instead. --}}
            useLink() {
                if (this.imageTab === 'url') return;
                this.imageTab = 'url';
                this.clearMedia();
                this.mediaError = null;
                if (this.$refs.image) this.$refs.image.value = '';
                this.forgetFiles();
            },
            {{-- A video or animated GIF goes to $video, anything else to $image. Some systems give
                 a clip no type at all; its name decides then. --}}
            pickMedia(file) {
                this.mediaError = null;
                this.clearMedia();
                this.forgetFiles();
                if (!file) return;
                this.useFile();
                const pick = this.pick;
                const current = () => pick === this.pick;
                const isVideo = file.type.startsWith('video/') || file.type === 'image/gif' || file.type === 'application/mp4'
                    || (file.type === '' && /\.(mp4|m4v|mov|webm|gif)$/i.test(file.name));
                const field = isVideo ? 'video' : 'image';
                if (isVideo && file.size > this.maxVideoBytes) {
                    this.mediaError = @js(__('ui.upload.error_video_too_large', ['megabytes' => intdiv((int) config('uploads.videos.max_kilobytes'), 1024)]));
                    this.$refs.image.value = '';
                    return;
                }
                this.fileName = file.name;
                const send = () => {
                    if (!current()) return;
                    this.uploadProgress = 0;
                    this.$wire.upload(field, file,
                        () => { if (current()) this.uploadProgress = null; },
                        () => { if (!current()) return; this.uploadProgress = null; this.$wire.set(field, null, false); this.mediaError = @js(__('ui.upload.error_generic')); },
                        (event) => { if (current()) this.uploadProgress = event.detail.progress; },
                    );
                };
                if (isVideo && !/gif$/i.test(file.type || file.name)) {
                    this.previewIsVideo = true;
                    this.previewUrl = URL.createObjectURL(file);
                    {{-- Its length is known from the metadata before a byte is sent, so a clip that is
                         too long is refused here rather than after a long upload. --}}
                    const probe = document.createElement('video');
                    probe.preload = 'metadata';
                    probe.onloadedmetadata = () => {
                        if (!current()) return;
                        if (Number.isFinite(probe.duration) && probe.duration > this.maxVideoSeconds + 0.5) {
                            this.mediaError = @js(__('ui.upload.error_video_too_long', ['seconds' => (int) config('uploads.videos.max_duration_seconds')]));
                            this.clearMedia();
                            this.$refs.image.value = '';
                            return;
                        }
                        send();
                    };
                    probe.onerror = send;
                    probe.src = this.previewUrl;
                    return;
                }
                const reader = new FileReader();
                reader.onload = e => { if (current()) this.previewUrl = e.target.result; };
                reader.readAsDataURL(file);
                send();
            },
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
            clearMedia();
            {{-- An upload still on its way from before would land in the fresh form. --}}
            $wire.cancelUpload('image');
            $wire.cancelUpload('video');
            mediaError = null;
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
                dir="auto"
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
                dir="auto"
                wire:model.defer="description"
                rows="3"
                :placeholder="__('ui.upload.description_placeholder')"
                class="mt-1"
            />
            <div data-testid="field-error-description" class="mt-1">
                <x-input-error :messages="$errors->get('description')" />
            </div>
        </div>

        {{-- The post's picture: one framed field takes a file — picked or dropped — or a link, with no
             tabs to switch between. Whichever is given last is the one posted. --}}
        <div data-testid="upload-media-input">
            <x-input-label :value="__('ui.upload.image')" />

            <div class="mt-1 rounded-rgCard border border-rg-border bg-rg-card2 p-3" data-testid="upload-media-field">
            {{-- A file --}}
            <div>
                <label
                    for="image"
                    class="flex cursor-pointer flex-col items-center justify-center rounded-rgCard border border-dashed border-rg-border2 bg-rg-card px-4 py-6 text-center transition hover:border-rg-accentBorder"
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
                        accept="image/*,{{ implode(',', config('uploads.videos.mimetypes')) }}"
                        x-ref="image"
                        class="sr-only"
                        x-on:change="pickMedia($event.target.files[0])"
                    />

                    <template x-if="previewUrl && imageTab === 'file' && !previewIsVideo">
                        <img :src="previewUrl" alt="{{ __('ui.upload.selected_image_alt') }}" decoding="async" class="max-h-56 w-full rounded-rgMedia object-contain" />
                    </template>

                    <template x-if="previewUrl && imageTab === 'file' && previewIsVideo">
                        <video :src="previewUrl" muted playsinline autoplay loop aria-label="{{ __('ui.upload.selected_video_label') }}" data-testid="upload-video-preview" class="max-h-56 w-full rounded-rgMedia object-contain"></video>
                    </template>

                    <div x-show="!previewUrl || imageTab !== 'file'" class="flex flex-col items-center gap-2">
                        <span class="grid size-10 place-items-center rounded-rgPill border border-rg-border2 bg-rg-card text-rg-muted">
                            <x-ui.icon name="upload" class="size-5" />
                        </span>
                        <div>
                            <p class="text-sm font-semibold text-rg-text">{{ __('ui.upload.image_drop_text') }}</p>
                            <p class="mt-1 text-xs text-rg-muted">{{ __('ui.upload.image_drop_hint') }}</p>
                            <p class="mt-1 text-xs text-rg-muted" data-testid="upload-video-hint">{{ __('ui.upload.media_drop_hint_video', ['seconds' => (int) config('uploads.videos.max_duration_seconds'), 'megabytes' => intdiv((int) config('uploads.videos.max_kilobytes'), 1024)]) }}</p>
                        </div>
                        <span class="mt-1 inline-flex h-9 items-center rounded-rgControl border border-rg-border2 bg-rg-card px-4 text-[13px] font-semibold text-rg-text">
                            {{ __('ui.upload.image_browse') }}
                        </span>
                    </div>

                    <p x-show="fileName && imageTab === 'file'" x-text="fileName" class="mt-2 text-xs text-rg-muted"></p>

                    <div x-show="uploadProgress !== null" x-cloak class="mt-2 w-full" data-testid="upload-progress">
                        <div class="h-1.5 w-full overflow-hidden rounded-rgPill bg-rg-border">
                            <div class="h-full bg-rg-accent transition-all" :style="`width: ${uploadProgress ?? 0}%`"></div>
                        </div>
                        <p class="mt-1 text-xs text-rg-muted" x-text="@js(__('ui.upload.uploading_progress', ['percent' => '__P__'])).replace('__P__', uploadProgress ?? 0)"></p>
                    </div>
                </label>

                <p x-show="mediaError" x-cloak x-text="mediaError" role="alert" data-testid="upload-media-error" class="mt-1 text-sm text-rg-dangerText"></p>
            </div>

            {{-- Or a link --}}
            @if($uploadSettings->featureFlag('allow_url_imports'))
            <div class="mt-3 space-y-2">
                <p class="flex items-center gap-3 text-sm font-semibold text-rg-text" aria-hidden="true" data-testid="upload-or-paste-link">
                    <span class="h-px flex-1 bg-rg-border"></span>
                    {{ __('ui.upload.or_paste_link') }}
                    <span class="h-px flex-1 bg-rg-border"></span>
                </p>
                <x-ui.input
                    name="importedImageUrl"
                    type="url"
                    :raised="true"
                    wire:model.defer="importedImageUrl"
                    :placeholder="__('ui.upload.image_url_placeholder')"
                    :aria-label="__('ui.upload.or_paste_link')"
                    data-testid="upload-image-url-input"
                    x-on:input="$event.target.value.trim() !== '' ? useLink() : (imageTab = 'file')"
                    x-on:input.debounce.800ms="$wire.previewLink($event.target.value)"
                />
                <p class="text-xs text-rg-muted" data-testid="upload-link-hint">{{ __('ui.upload.media_url_hint') }}</p>
                <div data-testid="field-error-image-url">
                    <x-input-error :messages="$errors->get('importedImageUrl')" />
                </div>
                {{-- What the link would post, read as it is pasted: its picture, or its video's still
                     and length. A still the site does not let another page show is simply left out,
                     and the video is still said to be one. --}}
                <p wire:loading wire:target="previewLink" class="text-xs text-rg-muted" data-testid="upload-link-reading">{{ __('ui.upload.link_reading') }}</p>
                @if($linkPreview)
                    <div x-show="imageTab === 'url'" wire:loading.remove wire:target="previewLink" data-testid="upload-link-preview" class="overflow-hidden rounded-rgMedia border border-rg-border bg-rg-card2">
                        @if($linkPreview['image'])
                            <img src="{{ $linkPreview['image'] }}" alt="{{ __('ui.upload.url_image_alt') }}" decoding="async" referrerpolicy="no-referrer" x-on:error="$el.remove()" class="max-h-48 w-full object-contain" />
                        @endif
                        @if($linkPreview['video'])
                            <p class="flex items-center gap-2 px-3 py-2 text-sm text-rg-text" data-testid="upload-link-preview-video">
                                <x-ui.icon name="play" class="size-4" />
                                <span>{{ __('ui.upload.link_preview_video') }}@if($linkPreview['duration']) · {{ $linkPreview['duration'] }}@endif</span>
                            </p>
                        @endif
                    </div>
                @endif
            </div>
            @endif
            </div>

            <div data-testid="field-error-image" class="mt-1">
                <x-input-error :messages="$errors->get('image')" />
            </div>
            <div data-testid="field-error-video" class="mt-1">
                <x-input-error :messages="$errors->get('video')" />
            </div>
        </div>

        {{-- Author's optional ground-truth answers to the voting questions. The toggle
             is Alpine-driven (entangled, no server round trip): with a wire:model.live
             checkbox the morph re-rendered the section and repainted the box unchecked,
             so the click never appeared to register. --}}
        @if($ratingGroups->isNotEmpty())
            {{-- No space-y here: it would put a margin under the checkbox row even while
                 the answers block is display:none (v4 space-y margins skip only the last
                 child, and the hidden block still counts as one), making the section's
                 bottom padding look bigger than the top. The answers block carries its
                 own mt-3 instead. --}}
            <div
                class="rounded-rgCard border border-rg-border bg-rg-card2 p-3"
                data-testid="upload-author-section"
                x-data="{ knowsAnswer: $wire.entangle('knowsCorrectAnswer') }"
            >
                <div class="flex items-start gap-3">
                    <x-ui.checkbox
                        id="knows_correct_answer"
                        x-model="knowsAnswer"
                        data-testid="upload-knows-answer-toggle"
                        class="mt-0.5"
                    />
                    <div>
                        <label for="knows_correct_answer" class="cursor-pointer text-sm font-medium text-rg-text">
                            {{ __('ui.upload.knows_answer') }}
                        </label>
                        <p class="mt-0.5 text-xs text-rg-muted">{{ __('ui.upload.knows_answer_hint') }}</p>
                    </div>
                </div>

                <div class="mt-3 space-y-3" data-testid="upload-author-answers" x-show="knowsAnswer" x-cloak>
                    @foreach($ratingGroups as $group)
                        <div>
                            <x-input-label for="author_answer_{{ $group->id }}" :value="$group->translatedLabel()" />
                            <select
                                id="author_answer_{{ $group->id }}"
                                wire:model.defer="authorAnswers.{{ $group->id }}"
                                data-testid="upload-author-answer-{{ $group->key }}"
                                class="mt-1 block w-full rounded-rgControl border border-rg-border2 bg-rg-card px-3 py-2 text-sm text-rg-text shadow-sm focus:border-rg-accent focus:outline-none focus:ring-1 focus:ring-rg-accent"
                            >
                                <option value="">{{ __('ui.upload.answer_not_selected') }}</option>
                                @foreach($group->options as $option)
                                    <option value="{{ $option->id }}">{{ $option->translatedLabel() }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                    <div data-testid="field-error-author-answers" class="mt-1">
                        <x-input-error :messages="collect($errors->get('authorAnswers.*'))->flatten()->all()" />
                    </div>
                </div>
            </div>
        @endif

        {{-- Source URL — hidden when image loaded from URL (already captured above) --}}
        @if($uploadSettings->featureFlag('allow_url_imports'))
        {{-- Out of the link tab's way, unless the server found it wrong: then it is shown with what is
             wrong. Keyed by that, so the answer draws it afresh — a redraw keeps an x-show as it was. --}}
        <div
            wire:key="upload-source-{{ $errors->has('sourceUrl') ? 'invalid' : 'valid' }}"
            x-show="imageTab !== 'url' || @js($errors->has('sourceUrl'))"
        >
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
                            <bdi>{{ $tag['name'] }}</bdi>
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
                        dir="auto"
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
                    class="absolute inset-x-0 z-30 mt-1 max-h-52 overflow-y-auto rounded-rgControl border border-rg-border bg-rg-card2 p-1 shadow-rgDropdown"
                    data-testid="upload-tag-menu"
                    role="listbox"
                    id="upload-tag-listbox"
                >
                    @forelse($unselectedTags as $tag)
                        <button
                            type="button"
                            wire:click="toggleTag({{ $tag['id'] }})"
                            class="flex w-full cursor-pointer items-center rounded-rgSm px-3 py-1.5 text-start text-[13px] text-rg-text2 transition hover:bg-rg-card hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
                            data-testid="upload-tag-{{ $tag['id'] }}"
                            role="option"
                            id="upload-tag-option-{{ $tag['id'] }}"
                            aria-selected="false"
                        >
                            <bdi>{{ $tag['name'] }}</bdi>
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

        {{-- Category — public, feeds the sidebar "Categories" filter --}}
        @if($categories->isNotEmpty())
            <div>
                <x-input-label for="category_id" :value="__('ui.upload.category')" />
                <select
                    id="category_id"
                    wire:model.defer="categoryId"
                    data-testid="upload-category-select"
                    class="mt-1 block w-full rounded-rgControl border border-rg-border2 bg-rg-card px-3 py-2 text-sm text-rg-text shadow-sm focus:border-rg-accent focus:outline-none focus:ring-1 focus:ring-rg-accent"
                >
                    <option value="">{{ __('ui.upload.category_placeholder') }}</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->translatedName() }}</option>
                    @endforeach
                </select>
                <div data-testid="field-error-category" class="mt-1">
                    <x-input-error :messages="$errors->get('categoryId')" />
                </div>
            </div>
        @endif

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
    @endif
</div>
