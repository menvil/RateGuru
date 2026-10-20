{{--
    Step 1 · Image. The plan's photo — previewed, with what it is, Replace
    and Remove from draft — or, while there is none, where one comes from:
    the computer, through Upload image or dropped on the drop zone, a direct
    link to an image, or a public social post. Nothing waits for Save: a file
    is uploaded, stored on the plan (a new publication's Draft made for it
    first) and analysed the moment it is chosen, and a link is imported once
    it is complete. A file dropped on the photo replaces it the same way.

    The browser's half: the preview of a chosen file at once, in place of
    where photos come from until the upload is over, the upload's progress —
    Uploading photo…, then Processing photo… while the server stores it — and
    an import's, announced as `rg-studio-photo-phase` to the analysis card
    beside it and to Continue. The chosen file stays shown until the photo
    the server kept has loaded in its place, so the photo never blinks out.
    One photo at a time: nothing new is taken while one is on its way. A link
    is sent only once it is a whole address, after typing has paused, and
    never twice running — the server says what was kept, and the browser
    forgets what it sent once a photo is kept or removed.

    Beside it, Photo analysis of the saved photo (StudioPlanImageAnalysisCard),
    drawn afresh for every photo so it is always about the one shown.
--}}
@php
    $hasPhoto = $shownPhoto !== null;
@endphp
<div
    class="rg-admin-studio__columns rg-admin-studio__columns--photo"
    x-data="{
        phase: '',
        progress: 0,
        name: '',
        // The chosen file's address, kept until another is chosen — an image losing it would blank before it hides.
        preview: null,
        previewing: false,
        // Whether the plan has a saved photo, as the server last said.
        saved: false,
        dragging: false,
        replacing: false,
        imported: { link: '', post: '' },
        incomplete: { link: false, post: false },
        get busy() {
            return this.phase !== ''
        },
        begin(phase) {
            this.phase = phase
            window.dispatchEvent(new CustomEvent('rg-studio-photo-phase', { detail: { phase } }))
        },
        finish() {
            this.progress = 0
            this.begin('')

            // The photo the server kept may still be loading: the chosen file stays in its place until it has.
            const kept = this.$refs.kept

            if (! this.previewing || ! kept || kept.complete) {
                this.previewing = false

                return
            }

            kept.addEventListener('load', () => this.previewing = false, { once: true })
            kept.addEventListener('error', () => this.previewing = false, { once: true })
        },
        send(file) {
            this.dragging = false

            if (! file || this.busy) {
                return
            }

            if (this.preview) {
                URL.revokeObjectURL(this.preview)
            }

            this.name = file.name
            this.preview = URL.createObjectURL(file)
            this.previewing = true
            this.begin('uploading')
            this.$wire.upload('photo', file, () => this.finish(), () => this.finish(), (event) => {
                this.progress = event.detail.progress

                if (this.progress >= 100 && this.phase === 'uploading') {
                    this.begin('processing')
                }
            }, () => this.finish())
        },
        importFrom(source, value) {
            const url = (value ?? '').trim()

            this.incomplete[source] = url !== '' && ! /^https?:\/\/[^\s\/?#]+\.[a-z]{2,}(?:[\/?#]\S*)?$/i.test(url)

            if (this.busy || url === '' || this.incomplete[source] || this.imported[source] === url) {
                return
            }

            this.imported[source] = url
            this.begin('importing')
            this.$wire.importImage(source).finally(() => this.finish())
        },
        retry() {
            this.begin('importing')
            this.$wire.retryImport().finally(() => this.finish())
        },
    }"
    x-init="saved = {{ $hasPhoto ? 'true' : 'false' }}"
    x-on:rg-studio-photo-kept.window="replacing = false; imported = { link: '', post: '' }; saved = $event.detail.saved"
>
    <x-admin.ui.card flush title="Photo for the publication" description="Upload a file, paste an image link or import from a social post. The upload rules and checks every post's image goes through apply.">
        @if ($hasPhoto)
            <x-slot:aside>
                <x-admin.ui.badge tone="success" :dot="true">{{ $replacedPhoto !== null ? 'Replaced' : 'Saved' }}</x-admin.ui.badge>
            </x-slot:aside>
        @endif

        <div class="rg-admin-studio__progress" x-show="busy" x-cloak role="status">
            <div class="rg-admin-studio__progress-line">
                <x-admin.ui.icon name="loader" :size="15" class="rg-admin-studio__spinner" />
                <span class="rg-admin-studio__progress-text">
                    <span x-show="phase === 'uploading'">Uploading photo…</span>
                    <span x-show="phase === 'processing'">Processing photo…</span>
                    <span x-show="phase === 'importing'">Importing photo…</span>
                    <span class="rg-admin-studio__progress-name" x-show="phase !== 'importing'" x-text="name"></span>
                </span>
                <span class="rg-admin-studio__progress-value" x-show="phase === 'uploading'" x-text="`${progress}%`"></span>
            </div>
            <div class="rg-admin-studio__progress-track">
                <div
                    class="rg-admin-studio__progress-bar"
                    x-bind:class="{ 'rg-admin-studio__progress-bar--waiting': phase !== 'uploading' }"
                    x-bind:style="{ width: `${phase === 'uploading' ? progress : 100}%` }"
                ></div>
            </div>
        </div>

        {{--
            The photo: a chosen file at once while it is on its way, then the
            saved photo once it has loaded. One block throughout, the chosen
            file's image the same element all along, so nothing is drawn
            afresh as a file becomes the saved photo; the saved photo is keyed
            by itself, so a new one is. Whether it shows is the browser's to
            say, from what the server last said of the photo, so a redraw
            never hides or shows it out of turn.
        --}}
        <div
            class="rg-admin-card__body rg-admin-studio__stack"
            wire:key="studio-plan-photo"
            wire:ignore.self
            x-show="saved || previewing"
            @unless ($hasPhoto) x-cloak @endunless
        >
            <figure
                class="rg-admin-studio__preview"
                x-on:dragover.prevent="dragging = ! busy"
                x-on:dragleave.prevent="dragging = false"
                x-on:drop.prevent="send($event.dataTransfer?.files?.[0])"
            >
                {{-- The browser's own: the server never draws it again, so its address is never taken away mid-upload. --}}
                <img alt="The chosen photo" class="rg-admin-studio__preview-image" wire:key="studio-plan-photo-chosen" wire:ignore x-show="previewing" x-bind:src="preview" x-cloak />
                @if ($hasPhoto && $shownPhoto['url'] !== null)
                    <img src="{{ $shownPhoto['url'] }}" alt="Photo for the publication" class="rg-admin-studio__preview-image" wire:key="studio-plan-photo-{{ $analysisImageId }}" x-ref="kept" x-show="! previewing" />
                @elseif ($hasPhoto)
                    <span class="rg-admin-studio__preview-empty" x-show="! previewing"><x-admin.ui.icon name="image" :size="32" />Preview unavailable</span>
                @endif
                <span class="rg-admin-studio__preview-drop" x-show="dragging" x-cloak>Drop to replace the photo</span>
            </figure>
            @if ($hasPhoto)
                <div class="rg-admin-studio__photo-meta">
                    <div class="rg-admin-studio__photo-text">
                        <p class="rg-admin-studio__photo-name">{{ $shownPhoto['name'] ?? 'Photo' }}</p>
                        @if ($shownPhoto['details'] !== [])
                            <p class="rg-admin-studio__photo-details">{{ implode(' · ', $shownPhoto['details']) }}</p>
                        @endif
                    </div>
                    <x-admin.ui.button size="sm" icon="replace" x-bind:disabled="busy || replacing" x-on:click="replacing = true">Replace</x-admin.ui.button>
                    <x-admin.ui.icon-button size="sm" icon="trash-2" label="Remove from draft" x-bind:disabled="busy" wire:click="removePhoto" wire:loading.attr="disabled" wire:target="removePhoto" />
                </div>
                @if ($replacedPhoto !== null)
                    <p class="rg-admin-hint">Replaced {{ $replacedPhoto }}.{{ $analysisConfigured ? ' The analysis runs again for the new photo.' : '' }}</p>
                @endif
            @endif
        </div>

        {{--
            Where a new photo comes from: open while there is none, and on
            Replace — and out of the way while a chosen file is on its way, so
            only its preview and progress show. Keyed apart, so each case is
            drawn afresh.
        --}}
        <div
            class="rg-admin-card__body rg-admin-studio__stack rg-admin-studio__chooser"
            wire:key="studio-plan-photo-chooser-{{ $hasPhoto ? 'replace' : 'first' }}"
            x-show="{{ $hasPhoto ? 'replacing && ! previewing' : '! previewing' }}"
            @if ($hasPhoto) x-cloak @endif
        >
            <div class="rg-admin-studio__chooser-bar">
                <x-admin.ui.segmented
                    label="Image source"
                    class="rg-admin-studio__segmented"
                    :options="['upload' => 'Upload', 'link' => 'From link', 'post' => 'From social post']"
                    :icons="['upload' => 'upload', 'link' => 'link', 'post' => 'share-2']"
                    :value="$imageSource"
                    wire:model.live="imageSource"
                />
                @if ($hasPhoto)
                    <x-admin.ui.button size="sm" x-bind:disabled="busy" x-on:click="replacing = false">Cancel</x-admin.ui.button>
                @endif
            </div>

            @if ($imageSource === 'upload')
                <div
                    class="rg-admin-studio__dropzone"
                    wire:key="studio-plan-image-upload"
                    x-bind:class="{ 'rg-admin-studio__dropzone--dragging': dragging }"
                    x-on:dragover.prevent="dragging = ! busy"
                    x-on:dragleave.prevent="dragging = false"
                    x-on:drop.prevent="send($event.dataTransfer?.files?.[0])"
                >
                    <span class="rg-admin-studio__dropzone-icon" aria-hidden="true"><x-admin.ui.icon name="image-up" :size="22" /></span>
                    <p class="rg-admin-studio__dropzone-title">Drop a photo here</p>
                    <p class="rg-admin-studio__dropzone-hint">JPEG, PNG or WebP, up to {{ $maxImageSize }}</p>
                    <x-admin.ui.button variant="primary" icon="upload" x-bind:disabled="busy" x-on:click="$refs.photo.click()" aria-describedby="rg-admin-studio-photo-note">Upload image</x-admin.ui.button>
                    <input
                        type="file"
                        id="rg-admin-studio-photo"
                        class="rg-admin-sr-only"
                        tabindex="-1"
                        aria-hidden="true"
                        accept="image/jpeg,image/png,image/webp"
                        x-ref="photo"
                        x-on:change="send($event.target.files[0]); $event.target.value = ''"
                    />
                    <p id="rg-admin-studio-photo-note" class="rg-admin-hint">Saved to this draft and analysed as soon as it is uploaded.</p>
                </div>
                @error('photo')
                    <x-admin.ui.inline-notice tone="warning" role="alert">
                        <strong>Upload failed.</strong> {{ $message }}
                        <x-slot:actions>
                            <x-admin.ui.button size="sm" x-bind:disabled="busy" x-on:click="$refs.photo.click()">Try again</x-admin.ui.button>
                        </x-slot:actions>
                    </x-admin.ui.inline-notice>
                @enderror
            @elseif ($imageSource === 'link')
                <div class="rg-admin-studio__stack" wire:key="studio-plan-image-link">
                    <x-admin.ui.text-field
                        label="Image link"
                        id="rg-admin-studio-image-url"
                        type="url"
                        icon="link"
                        :value="$imageUrl"
                        placeholder="https://example.com/photo.jpg"
                        hint="A direct link to a JPEG, PNG or WebP file, copied into the site's storage as soon as it is pasted. The post's source address is set on the Post step."
                        :error="$errors->first('imageUrl') ?: null"
                        autocomplete="off"
                        spellcheck="false"
                        wire:model="imageUrl"
                        x-bind:readonly="busy"
                        x-on:input.debounce.800ms="importFrom('link', $event.target.value)"
                        x-on:keydown.enter.prevent="importFrom('link', $event.target.value)"
                    />
                    <p class="rg-admin-hint" x-show="incomplete.link" x-cloak>Paste the full link, starting with https://</p>
                    @error('imageUrl')
                        <div><x-admin.ui.button size="sm" icon="rotate-ccw" x-bind:disabled="busy" x-on:click="retry()">Try again</x-admin.ui.button></div>
                    @enderror
                </div>
            @else
                <div class="rg-admin-studio__stack" wire:key="studio-plan-image-post">
                    <x-admin.ui.text-field
                        label="Post link"
                        id="rg-admin-studio-post-url"
                        type="url"
                        icon="share-2"
                        :value="$postUrl"
                        placeholder="https://www.instagram.com/p/…"
                        hint="A public post. Its image is copied into the site's storage as soon as the link is pasted."
                        :error="$errors->first('postUrl') ?: null"
                        autocomplete="off"
                        spellcheck="false"
                        wire:model="postUrl"
                        x-bind:readonly="busy"
                        x-on:input.debounce.800ms="importFrom('post', $event.target.value)"
                        x-on:keydown.enter.prevent="importFrom('post', $event.target.value)"
                    />
                    <p class="rg-admin-hint" x-show="incomplete.post" x-cloak>Paste the full link, starting with https://</p>
                    <ul class="rg-admin-studio__platforms" aria-label="Sites whose posts are read directly">
                        @foreach ($platforms as $platform)
                            <li @class(['rg-admin-studio__platform', 'rg-admin-studio__platform--detected' => $platform === $detectedPlatform])>{{ $platform }}</li>
                        @endforeach
                    </ul>
                    <p class="rg-admin-hint">{{ $detectedPlatform !== null ? "Detected: {$detectedPlatform} post." : 'Any other public page with a preview image works too.' }}</p>
                    @error('postUrl')
                        <div><x-admin.ui.button size="sm" icon="rotate-ccw" x-bind:disabled="busy" x-on:click="retry()">Try again</x-admin.ui.button></div>
                    @enderror
                </div>
            @endif
        </div>
    </x-admin.ui.card>

    <livewire:admin.studio-plan-image-analysis-card
        :plan-id="$analysisPlanId"
        :image-asset-id="$analysisImageId"
        :wire:key="'studio-plan-image-analysis-'.$analysisPlanId.'-'.($analysisImageId ?? 'none')"
    />
</div>
