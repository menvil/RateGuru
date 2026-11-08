@php
    /*
     * A game's General tab (EditGameGeneral), and a new game (CreateGame):
     * the public title, the internal name, the slug — made from the title
     * until it is typed, locked once a version has been published — and the
     * description; beside them the cover, stored the moment it is chosen, and
     * the mechanic. The browser counts what is unsaved against `saved`
     * (filament.pages.editor.script) and asks before the page is left with
     * unsaved changes.
     */
    $draft = '{ gameTitle: $wire.gameTitle, internalName: $wire.internalName, gameSlug: $wire.gameSlug, gameDescription: $wire.gameDescription }';
    $blocked = $creating ? 'false' : '$wire.stale || $wire.gone';
@endphp

<div
    class="rg-admin rg-admin-screen rg-admin-games"
    x-data
    x-effect="$store.adminEditor.changes = rgAdminEditorChanges({{ $draft }}, $wire.saved); $store.adminEditor.blocked = {{ $blocked }}"
    x-on:beforeunload.window="if (rgAdminEditorChanges({{ $draft }}, $wire.saved) > 0) { $event.preventDefault(); $event.returnValue = '' }"
>
    @include('filament.pages.editor.script')

    @if ($header !== null)
        @include('filament.pages.games.editor-header', ['header' => $header])
    @else
        <section class="rg-admin-page-header" aria-labelledby="rg-admin-game-title">
            <div class="rg-admin-page-header__text">
                <h1 id="rg-admin-game-title" class="rg-admin-page-header__title">New game</h1>
                <p class="rg-admin-page-header__description">English is the source text; other languages are translated from it in Translation Center. Creating the game publishes nothing.</p>
            </div>
        </section>
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
            <x-admin.ui.card title="General information" description="What visitors see of the game, and the name only admins see.">
                <div class="rg-admin-games__form">
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
                        :generated="! $slugLocked && ! $slugTyped ? 'Made from the title' : null"
                        :hint="$slugLocked ? 'Shared links and ads point to this address.' : 'Lowercase letters, numbers and hyphens. It locks once a version is published.'"
                        :error="$errors->first('slug') ?: null"
                        wire:model.live.debounce.400ms="gameSlug"
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
                </div>
            </x-admin.ui.card>

            <div class="rg-admin-games__aside">
                <x-admin.ui.card title="Cover" description="Landscape, at least 1600 × 900. Used on the landing page and as the share image when a profile has none.">
                    @if ($creating)
                        <p class="rg-admin-games__note">Add a cover once the game is created.</p>
                    @else
                        <div class="rg-admin-games__cover">
                            @if ($cover !== null)
                                <img src="{{ $cover }}" alt="Cover of {{ $gameTitle }}" class="rg-admin-games__cover-image" />
                            @else
                                <span class="rg-admin-games__cover-empty"><x-admin.ui.icon name="image" :size="20" label="No cover" /></span>
                            @endif
                        </div>
                        <div class="rg-admin-games__cover-actions">
                            <label class="rg-admin-button rg-admin-button--secondary rg-admin-button--sm" for="rg-admin-game-cover-input">
                                <x-admin.ui.icon name="upload" :size="14" />
                                {{ $cover !== null ? 'Replace cover' : 'Upload cover' }}
                            </label>
                            <input id="rg-admin-game-cover-input" type="file" accept="image/jpeg,image/png,image/webp" class="rg-admin-sr-only" wire:model="coverUpload" />
                            @if ($cover !== null)
                                <x-admin.ui.button size="sm" variant="ghost" icon="trash-2" wire:click="removeCover">Remove</x-admin.ui.button>
                            @endif
                        </div>
                        <div wire:loading wire:target="coverUpload" class="rg-admin-games__note">Uploading…</div>
                        @error('coverUpload')
                            <p class="rg-admin-field__error" role="alert">{{ $message }}</p>
                        @enderror
                    @endif
                </x-admin.ui.card>

                <x-admin.ui.card title="Mechanic" description="How the game is played. It locks once a version is published.">
                    <div class="rg-admin-games__mechanic">
                        <span class="rg-admin-games__mechanic-icon" aria-hidden="true"><x-admin.ui.icon name="columns-2" :size="18" /></span>
                        <span class="rg-admin-games__mechanic-text">
                            <span class="rg-admin-games__mechanic-name">{{ $mechanic?->label() ?? 'Versus' }}</span>
                            <span class="rg-admin-games__note">{{ $mechanic?->description() ?? 'Choose between two images.' }} Players tap one photo, then see how the crowd voted on the site.</span>
                        </span>
                    </div>
                </x-admin.ui.card>
            </div>
        </div>
    </div>
</div>
