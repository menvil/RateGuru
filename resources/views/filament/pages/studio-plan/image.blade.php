{{--
    Step 1 · Image. The photo as the draft has it — the one chosen, or the
    saved one unless Save takes it off — with what it is and how to change
    it, and where a new one comes from: the computer, through the button or
    by dropping a file, or a direct link to an image. Nothing is stored until
    the draft is saved. Beside it, photo analysis of the saved photo
    (StudioPlanImageAnalysisCard), asked for on its own and never by Save.
--}}
<div class="rg-admin-studio__columns">
    <x-admin.ui.card title="Photo for the publication" description="Upload a file or use a direct link to an image. It is stored when the draft is saved.">
        <div class="rg-admin-studio__stack">
            @if (in_array($shownPhoto['state'], ['chosen', 'saved'], true))
                <figure class="rg-admin-studio__preview">
                    @if ($shownPhoto['url'] !== null)
                        <img src="{{ $shownPhoto['url'] }}" alt="Photo for the publication" class="rg-admin-studio__preview-image" />
                    @else
                        <span class="rg-admin-studio__preview-empty"><x-admin.ui.icon name="image" :size="32" />Preview unavailable</span>
                    @endif
                </figure>
                <div class="rg-admin-studio__photo-meta">
                    <div class="rg-admin-studio__photo-text">
                        <p class="rg-admin-studio__photo-name">{{ $shownPhoto['name'] ?? 'Photo' }}</p>
                        @if ($shownPhoto['details'] !== [])
                            <p class="rg-admin-studio__photo-details">{{ implode(' · ', $shownPhoto['details']) }}</p>
                        @endif
                    </div>
                    @if ($shownPhoto['state'] === 'chosen')
                        <x-admin.ui.badge tone="info">Not saved yet</x-admin.ui.badge>
                        <x-admin.ui.button size="sm" variant="ghost" wire:click="clearPhoto">{{ $creating ? 'Remove' : 'Keep the current photo' }}</x-admin.ui.button>
                    @else
                        <x-admin.ui.button size="sm" icon="trash-2" wire:click="removeSavedImage">Remove image</x-admin.ui.button>
                    @endif
                </div>
            @elseif ($shownPhoto['state'] === 'removing')
                <x-admin.ui.inline-notice tone="warning">
                    The photo is taken off this draft when you save. It is released if nothing else uses it.
                    <x-slot:actions>
                        <x-admin.ui.button size="sm" wire:click="keepSavedImage">Keep the photo</x-admin.ui.button>
                    </x-slot:actions>
                </x-admin.ui.inline-notice>
            @endif

            @if ($imageSource === 'link' && trim($imageUrl) !== '' && ! $errors->has('imageUrl'))
                <x-admin.ui.inline-notice tone="info">
                    The image at this link is downloaded, checked and stored when you save{{ $shownPhoto['state'] === 'saved' ? ', and replaces the photo above' : '' }}.
                </x-admin.ui.inline-notice>
            @endif

            <div class="rg-admin-field">
                <span class="rg-admin-label">{{ in_array($shownPhoto['state'], ['chosen', 'saved'], true) ? 'Choose another image' : 'Image source' }}</span>
                <x-admin.ui.segmented label="Image source" class="rg-admin-studio__segmented" :options="['upload' => 'Upload', 'link' => 'From link']" :icons="['upload' => 'upload', 'link' => 'link']" :value="$imageSource" wire:model.live="imageSource" />
            </div>

            @if ($imageSource === 'upload')
                {{-- Keyed apart from the link field, so switching sources draws each afresh with its own Alpine state. --}}
                <div
                    class="rg-admin-studio__dropzone"
                    wire:key="studio-plan-image-upload"
                    x-data="{
                        dragging: false,
                        drop(event) {
                            this.dragging = false
                            const file = event.dataTransfer?.files?.[0]

                            if (file) {
                                this.$wire.upload('photo', file, () => {}, () => {}, () => {})
                            }
                        },
                    }"
                    x-bind:class="{ 'rg-admin-studio__dropzone--dragging': dragging }"
                    x-on:dragover.prevent="dragging = true"
                    x-on:dragleave.prevent="dragging = false"
                    x-on:drop.prevent="drop($event)"
                >
                    <span class="rg-admin-studio__dropzone-icon" aria-hidden="true"><x-admin.ui.icon name="upload" :size="20" /></span>
                    <p class="rg-admin-studio__dropzone-title">Drop a photo here</p>
                    <p class="rg-admin-studio__dropzone-hint">JPEG, PNG or WebP, up to {{ $maxImageSize }}</p>
                    <x-admin.ui.button variant="primary" icon="upload" x-on:click="$refs.photo.click()" aria-describedby="rg-admin-studio-photo-note">
                        {{ in_array($shownPhoto['state'], ['chosen', 'saved'], true) ? 'Choose another image' : 'Upload image' }}
                    </x-admin.ui.button>
                    <input
                        type="file"
                        id="rg-admin-studio-photo"
                        class="rg-admin-sr-only"
                        tabindex="-1"
                        aria-hidden="true"
                        accept="image/jpeg,image/png,image/webp"
                        x-ref="photo"
                        wire:model="photo"
                    />
                    @error('photo')
                        <p id="rg-admin-studio-photo-note" class="rg-admin-error"><x-admin.ui.icon name="circle-alert" :size="12" />{{ $message }}</p>
                    @else
                        <p id="rg-admin-studio-photo-note" class="rg-admin-hint" wire:loading.remove wire:target="photo">The photo is stored when you save the draft.</p>
                        <p class="rg-admin-hint" wire:loading wire:target="photo">Uploading…</p>
                    @enderror
                </div>
            @else
                <div class="rg-admin-studio__stack" wire:key="studio-plan-image-link">
                    <x-admin.ui.text-field
                        label="Image URL"
                        id="rg-admin-studio-image-url"
                        type="url"
                        icon="link"
                        :value="$imageUrl"
                        placeholder="https://example.com/photo.jpg"
                        hint="A direct link to a JPEG, PNG or WebP file, downloaded when you save. The post’s source address is set on the Post step."
                        :error="$errors->first('imageUrl') ?: null"
                        autocomplete="off"
                        spellcheck="false"
                        wire:model="imageUrl"
                    />
                    @if ($imageUrl !== '')
                        <div><x-admin.ui.button size="sm" variant="ghost" wire:click="clearImageUrl">Don’t use this link</x-admin.ui.button></div>
                    @endif
                </div>
            @endif
        </div>
    </x-admin.ui.card>

    @php
        // Whether the draft is about to change the photo — a new one chosen,
        // a link to import one from, or the saved one taken off — read from
        // the draft as typed, like the count of unsaved changes.
        $newPhoto = "(\$wire.imageSource === 'upload' && \$wire.photo) || (\$wire.imageSource === 'link' && (\$wire.imageUrl ?? '').trim() !== '')";
        $photoPending = "({$newPhoto}) || \$wire.removeImage";
    @endphp
    <x-admin.ui.card title="Photo analysis" description="Technical context for the text generators. Not shown to visitors.">
        <div class="rg-admin-studio__stack" x-data>
            <div x-show="{{ $newPhoto }}" x-cloak>
                <x-admin.ui.inline-notice tone="info">Save the draft first to analyse the new photo.</x-admin.ui.inline-notice>
            </div>
            <div x-show="! ({{ $newPhoto }}) && $wire.removeImage" x-cloak>
                <x-admin.ui.inline-notice tone="info">The photo is taken off this draft when you save, so there is nothing to analyse.</x-admin.ui.inline-notice>
            </div>
            @if ($creating || $plan === null)
                <div x-show="! ({{ $photoPending }})">
                    <x-admin.ui.inline-notice tone="info">Save a photo to this draft before analysing it.</x-admin.ui.inline-notice>
                </div>
            @else
                {{-- Drawn afresh for every saved photo, so it is always about the one shown. --}}
                <div x-show="! ({{ $photoPending }})">
                    <livewire:admin.studio-plan-image-analysis-card
                        :plan-id="$plan['id']"
                        :image-asset-id="$plan['imageAssetId']"
                        :wire:key="'studio-plan-image-analysis-'.$plan['id'].'-'.($plan['imageAssetId'] ?? 'none')"
                    />
                </div>
            @endif
        </div>
    </x-admin.ui.card>
</div>
