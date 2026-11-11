@php
    /*
     * A game's General tab (EditGameGeneral): the public title, the internal
     * name, the slug — locked once a version has been published — the
     * description and the cover, stored the moment it is chosen; beside them
     * an example round of the game's mechanic. The browser counts what is
     * unsaved against `saved` (filament.pages.editor.script) and asks before
     * the page is left with unsaved changes.
     */
    $draft = '{ gameTitle: $wire.gameTitle, internalName: $wire.internalName, gameSlug: $wire.gameSlug, gameDescription: $wire.gameDescription }';
@endphp

<div
    class="rg-admin rg-admin-screen rg-admin-games"
    x-data
    x-effect="$store.adminEditor.changes = rgAdminEditorChanges({{ $draft }}, $wire.saved); $store.adminEditor.blocked = $wire.stale || $wire.gone"
    x-on:beforeunload.window="if (rgAdminEditorChanges({{ $draft }}, $wire.saved) > 0) { $event.preventDefault(); $event.returnValue = '' }"
>
    @include('filament.pages.editor.script')

    @if ($header !== null)
        @include('filament.pages.games.editor-header', ['header' => $header])
    @endif

    <div class="rg-admin-screen__body">
        @if ($gone)
            <x-admin.ui.inline-notice tone="warning">
                This game no longer exists, so nothing here can be saved.
                <x-slot:actions>
                    <x-admin.ui.button size="sm" icon="arrow-left" :href="\App\Filament\Resources\Games\Pages\ListGames::getUrl()">Back to games</x-admin.ui.button>
                </x-slot:actions>
            </x-admin.ui.inline-notice>
        @elseif ($stale)
            <x-admin.ui.inline-notice tone="warning">
                Someone saved this game after you opened it. Reload to see their changes; what you typed here is replaced.
                <x-slot:actions>
                    <x-admin.ui.button size="sm" icon="refresh-cw" wire:click="reload">Reload</x-admin.ui.button>
                </x-slot:actions>
            </x-admin.ui.inline-notice>
        @endif

        <div class="rg-admin-games__columns">
            <x-admin.ui.card title="General information" description="What visitors see of the game, and the name only admins see." :flush="true">
                <div class="rg-admin-games__form rg-admin-games__form--grid">
                    <x-admin.ui.text-field
                        label="Public title"
                        id="rg-admin-game-title-field"
                        :value="$gameTitle"
                        :error="$errors->first('title') ?: null"
                        :hint="'Shown on the landing page and share card. Up to '.$limits['title'].' characters.'"
                        wire:model.live.debounce.400ms="gameTitle"
                    />
                    <x-admin.ui.text-field
                        label="Internal name"
                        id="rg-admin-game-internal"
                        :value="$internalName"
                        :error="$errors->first('internalName') ?: null"
                        hint="Only admins see it, in the library and in analytics. Empty uses the public title."
                        wire:model="internalName"
                    />
                    <x-admin.ui.locked-identifier
                        label="Slug"
                        id="rg-admin-game-slug"
                        :value="$gameSlug"
                        prefix="/games/"
                        :locked="$slugLocked"
                        locked-note="Locked after the first publish"
                        :hint="$slugLocked ? 'Shared links and ads point to this address.' : 'Lowercase letters, numbers and hyphens. It locks once a version is published.'"
                        :error="$errors->first('slug') ?: null"
                        wire:model="gameSlug"
                    />
                    <x-admin.ui.textarea
                        label="Description"
                        id="rg-admin-game-description"
                        :value="$gameDescription"
                        :rows="3"
                        :limit="$limits['description']"
                        :required="false"
                        :error="$errors->first('description') ?: null"
                        hint="Shown when the game’s link is shared, and to search engines."
                        wire:model="gameDescription"
                    />

                    <div class="rg-admin-field" role="group" aria-labelledby="rg-admin-game-cover-label">
                        <div class="rg-admin-field__head">
                            <span id="rg-admin-game-cover-label" class="rg-admin-label">Cover image</span>
                        </div>
                        <div class="rg-admin-games__cover">
                            <span class="rg-admin-games__cover-thumb">
                                @if ($cover !== null)
                                    <img src="{{ $cover['url'] }}" alt="Cover of {{ $gameTitle }}" class="rg-admin-games__cover-image" />
                                @else
                                    <x-admin.ui.icon name="image" :size="20" />
                                    <span>1600 × 900</span>
                                @endif
                            </span>
                            <span class="rg-admin-games__mechanic-text">
                                <span class="rg-admin-games__mechanic-name rg-admin-games__cover-name">{{ $cover['name'] ?? 'No cover yet' }}</span>
                                <span class="rg-admin-games__note">{{ isset($cover['size']) ? $cover['size'].' · ' : '' }}Landscape, at least 1600 × 900. Used on the landing page and as the share image when a profile has none.</span>
                            </span>
                            <span class="rg-admin-games__cover-actions">
                                <label class="rg-admin-button rg-admin-button--secondary rg-admin-button--sm" for="rg-admin-game-cover-input">
                                    <x-admin.ui.icon name="upload" :size="14" />
                                    {{ $cover !== null ? 'Replace' : 'Upload cover' }}
                                </label>
                                <input id="rg-admin-game-cover-input" type="file" accept="image/jpeg,image/png,image/webp" class="rg-admin-sr-only" aria-labelledby="rg-admin-game-cover-label" wire:model="coverUpload" />
                                @if ($cover !== null)
                                    <x-admin.ui.button size="sm" variant="ghost" icon="trash-2" wire:click="removeCover">Remove</x-admin.ui.button>
                                @endif
                            </span>
                        </div>
                        <div wire:loading wire:target="coverUpload" class="rg-admin-games__note">Uploading…</div>
                        @error('coverUpload')
                            <p class="rg-admin-error" role="alert"><x-admin.ui.icon name="circle-alert" :size="12" /> {{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </x-admin.ui.card>

            @if ($mechanic !== null)
                @include('filament.pages.games.example-round', ['mechanic' => $mechanic, 'title' => $gameTitle])
            @endif
        </div>
    </div>
</div>
