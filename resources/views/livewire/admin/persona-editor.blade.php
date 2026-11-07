@php
    /*
     * A Persona's editor in the drawer over Personas (PersonaEditor, OVL-02 at
     * the editor's width), as the reference draws it: Profile — the public
     * face, as on its profile page — Character and Activity, which are Content
     * Studio's own and never shown on the site, then where the account stands.
     * Saved from the footer; Cancel, Escape and the scrim close it.
     *
     * The footer counts what is unsaved from the draft against `saved`
     * (filament.pages.editor.script, on the list), a chosen photo counting as
     * one change, and the browser asks before the page is left with any. An
     * editor drawn before somebody else's save, or of a Persona gone
     * meanwhile, holds Save back and says so.
     */
    $draft = '{ displayName: $wire.displayName, username: $wire.username, bio: $wire.bio, shortDescription: $wire.shortDescription, character: $wire.character, speechStyle: $wire.speechStyle, topics: $wire.topics, neverWritesAbout: $wire.neverWritesAbout, emojiFrequency: $wire.emojiFrequency, engagement: $wire.engagement, maxRepliesPerDay: $wire.maxRepliesPerDay, photo: $wire.photo ? \'new\' : \'\' }';
    $title = $creating ? 'New persona' : 'Edit '.($persona['displayName'] !== '' ? $persona['displayName'] : 'persona');
@endphp

<x-admin.ui.drawer
    id="rg-admin-persona-editor"
    editor
    :title="$title"
    subtitle="A regular account on the site, run by Content Studio"
    x-on:dismiss="$wire.dispatch('close-persona')"
    x-on:beforeunload.window="if (rgAdminEditorChanges({{ $draft }}, $wire.saved) > 0) { $event.preventDefault(); $event.returnValue = '' }"
>
    <x-slot:leading>
        <span class="rg-admin-avatar rg-admin-avatar--md">
            @if (! $creating && $persona['avatar'] !== null)
                <img src="{{ $persona['avatar'] }}" alt="" class="rg-admin-avatar__image" />
            @else
                {{ $creating ? ($avatar['initials'] !== '' ? $avatar['initials'] : '?') : $persona['initials'] }}
            @endif
        </span>
    </x-slot:leading>

    <form id="rg-admin-persona-editor-form" class="rg-admin-editor-drawer" wire:submit="save" novalidate>
        @if (! $creating && $gone)
            <div class="rg-admin-editor-drawer__notice">
                <x-admin.ui.inline-notice tone="warning">
                    This persona can’t be edited any more: its account was deleted, or it is no longer a persona.
                </x-admin.ui.inline-notice>
            </div>
        @elseif (! $creating && $stale)
            <div class="rg-admin-editor-drawer__notice">
                <x-admin.ui.inline-notice tone="warning">
                    Someone saved this persona after you opened it. Reload to see their changes; what you typed here is replaced.
                    <x-slot:actions>
                        <x-admin.ui.button size="sm" icon="refresh-cw" wire:click="reload">Reload</x-admin.ui.button>
                    </x-slot:actions>
                </x-admin.ui.inline-notice>
            </div>
        @endif

        <section class="rg-admin-editor-drawer__section" aria-labelledby="rg-admin-persona-editor-profile">
            <div class="rg-admin-section-label"><h3 id="rg-admin-persona-editor-profile" class="rg-admin-section-label__text">Profile</h3></div>

            <div class="rg-admin-persona__photo">
                <span class="rg-admin-avatar rg-admin-avatar--xl" aria-hidden="true">
                    @if ($avatar['url'] !== null)
                        <img src="{{ $avatar['url'] }}" alt="" class="rg-admin-avatar__image" />
                    @else
                        {{ $avatar['initials'] !== '' ? $avatar['initials'] : '?' }}
                    @endif
                </span>
                <div class="rg-admin-persona__photo-actions" x-data>
                    <div class="rg-admin-persona__photo-buttons">
                        <x-admin.ui.button size="sm" icon="upload" x-on:click="$refs.photo.click()" aria-describedby="rg-admin-persona-photo-note">
                            {{ $avatar['url'] !== null || $avatar['chosen'] ? 'Choose another photo' : 'Upload photo' }}
                        </x-admin.ui.button>
                        @if ($avatar['chosen'])
                            <x-admin.ui.button size="sm" variant="ghost" wire:click="clearPhoto">Keep the current photo</x-admin.ui.button>
                        @endif
                    </div>
                    <input
                        type="file"
                        id="rg-admin-persona-photo"
                        class="rg-admin-sr-only"
                        tabindex="-1"
                        aria-hidden="true"
                        accept="image/jpeg,image/png,image/webp"
                        x-ref="photo"
                        wire:model="photo"
                    />
                    @error('photo')
                        <p id="rg-admin-persona-photo-note" class="rg-admin-error"><x-admin.ui.icon name="circle-alert" :size="12" />{{ $message }}</p>
                    @else
                        <p id="rg-admin-persona-photo-note" class="rg-admin-hint" wire:loading.remove wire:target="photo">
                            @if ($avatar['chosen'])
                                The new photo is used once you save.
                            @elseif ($avatar['url'] !== null)
                                JPEG, PNG or WebP. A new photo replaces this one when you save.
                            @else
                                Initials until a photo is uploaded. JPEG, PNG or WebP.
                            @endif
                        </p>
                        <p class="rg-admin-hint" wire:loading wire:target="photo">Uploading…</p>
                    @enderror
                </div>
            </div>

            <div class="rg-admin-persona__fields">
                <x-admin.ui.text-field
                    label="Display name"
                    id="rg-admin-persona-display-name"
                    :value="$displayName"
                    placeholder="e.g. MaxPower77"
                    :required="true"
                    hint="Shown on its posts, comments and profile."
                    :error="$errors->first('displayName') ?: null"
                    maxlength="{{ $limits['displayName'] }}"
                    wire:model.live.debounce.400ms="displayName"
                />
                <x-admin.ui.text-field
                    label="Username"
                    id="rg-admin-persona-username"
                    icon="at-sign"
                    :value="$username"
                    placeholder="{{ $creating ? 'Made from the display name' : '' }}"
                    :required="$creating ? false : true"
                    :hint="$profilePath !== null
                        ? 'Public profile: '.$profilePath.($creating ? '' : '. Old profile links stop working after a change.')
                        : 'Leave empty to make one from the display name. Lowercase letters, numbers and underscores.'"
                    :error="$errors->first('username') ?: null"
                    maxlength="{{ $limits['username'] }}"
                    autocomplete="off"
                    wire:model.live.debounce.400ms="username"
                />
                <x-admin.ui.textarea
                    class="rg-admin-persona__wide"
                    label="Public bio"
                    id="rg-admin-persona-bio"
                    :value="$bio"
                    :rows="3"
                    :limit="$limits['bio']"
                    :required="false"
                    hint="Shown on its profile, like anyone’s bio."
                    :error="$errors->first('bio') ?: null"
                    wire:model="bio"
                />
            </div>

            <x-admin.ui.inline-notice tone="info" icon="user">
                On the site this is a regular account: its posts, comments and votes count like anyone’s, and its posts wait for moderation.
            </x-admin.ui.inline-notice>
        </section>

        <section class="rg-admin-editor-drawer__section" aria-labelledby="rg-admin-persona-editor-character">
            <div class="rg-admin-section-label"><h3 id="rg-admin-persona-editor-character" class="rg-admin-section-label__text">Character</h3></div>
            <p class="rg-admin-hint">Internal. Never shown on the site; the AI that writes as this persona will follow it.</p>

            <x-admin.ui.textarea
                label="Short description"
                id="rg-admin-persona-short-description"
                :value="$shortDescription"
                :rows="2"
                :limit="$limits['shortDescription']"
                :required="false"
                placeholder="Sarcastic, short double-meaning jokes"
                hint="One line to tell personas apart in Content Studio."
                :error="$errors->first('shortDescription') ?: null"
                wire:model="shortDescription"
            />
            <x-admin.ui.textarea
                label="Character"
                id="rg-admin-persona-character"
                :value="$character"
                :rows="4"
                :limit="$limits['notes']"
                :required="false"
                placeholder="Who this person is, how they think and what they care about…"
                hint="Who the persona is. The AI writes from it."
                :error="$errors->first('character') ?: null"
                wire:model="character"
            />
            <x-admin.ui.textarea
                label="Speech style"
                id="rg-admin-persona-speech-style"
                :value="$speechStyle"
                :rows="3"
                :limit="$limits['notes']"
                :required="false"
                placeholder="One-liners, plain words, the odd typo"
                hint="Sentence length, tone and typical phrasing the AI keeps to."
                :error="$errors->first('speechStyle') ?: null"
                wire:model="speechStyle"
            />
            <x-admin.ui.textarea
                label="Topics and typical jokes"
                id="rg-admin-persona-topics"
                :value="$topics"
                :rows="3"
                :limit="$limits['notes']"
                :required="false"
                placeholder="Lazy pets, owners who overdo it"
                hint="What it likes to talk about."
                :error="$errors->first('topics') ?: null"
                wire:model="topics"
            />
            <x-admin.ui.textarea
                label="Never writes about"
                id="rg-admin-persona-never-writes-about"
                :value="$neverWritesAbout"
                :rows="3"
                :limit="$limits['notes']"
                :required="false"
                placeholder="Politics, health advice, other users’ looks"
                hint="Limits the AI must keep to when it writes as this persona."
                :error="$errors->first('neverWritesAbout') ?: null"
                wire:model="neverWritesAbout"
            />
            <div class="rg-admin-field">
                <span class="rg-admin-label">Emoji</span>
                <x-admin.ui.segmented label="Emoji" class="rg-admin-persona__segmented" :options="$emojiOptions" :value="$emojiFrequency" wire:model="emojiFrequency" />
                @error('emojiFrequency')
                    <p class="rg-admin-error"><x-admin.ui.icon name="circle-alert" :size="12" />{{ $message }}</p>
                @enderror
            </div>
        </section>

        <section class="rg-admin-editor-drawer__section" aria-labelledby="rg-admin-persona-editor-activity">
            <div class="rg-admin-section-label"><h3 id="rg-admin-persona-editor-activity" class="rg-admin-section-label__text">Activity</h3></div>
            <p class="rg-admin-hint">Saved as settings for now: nothing replies on its own yet.</p>

            <div class="rg-admin-field">
                <span class="rg-admin-label">Engagement for this account</span>
                <x-admin.ui.segmented label="Engagement" class="rg-admin-persona__segmented" :options="$engagementOptions" :value="$engagement" wire:model="engagement" />
                @error('engagement')
                    <p class="rg-admin-error"><x-admin.ui.icon name="circle-alert" :size="12" />{{ $message }}</p>
                @else
                    <p class="rg-admin-hint" x-text="@js($engagementHelp)[$wire.engagement] ?? ''">{{ $engagementHelp[$engagement] ?? '' }}</p>
                @enderror
            </div>
            <x-admin.ui.number-stepper
                label="Max replies a day"
                id="rg-admin-persona-max-replies"
                :value="$maxRepliesPerDay"
                :min="0"
                :max="$limits['replies']"
                hint="Replies of its own, at most, from 0 to {{ $limits['replies'] }}."
                :error="$errors->first('maxRepliesPerDay') ?: null"
                wire:model="maxRepliesPerDay"
            />
            <p class="rg-admin-persona__note">
                Off stops only the replies it would write on its own. Approved publication plans still run while its account allows them; whether it may post, comment or vote at all is its status.
            </p>
        </section>

        <section class="rg-admin-editor-drawer__section" aria-labelledby="rg-admin-persona-editor-account">
            <div class="rg-admin-section-label"><h3 id="rg-admin-persona-editor-account" class="rg-admin-section-label__text">Account</h3></div>
            @if ($creating)
                <p class="rg-admin-persona__note">
                    Creating makes a regular account with the Persona role. It starts active, with no password and an address nothing is ever sent to.
                </p>
            @else
                <div class="rg-admin-persona__status">
                    <x-admin.ui.badge :tone="$persona['status']['tone']" :dot="$persona['status']['dot']">{{ $persona['status']['label'] }}</x-admin.ui.badge>
                    <span class="rg-admin-reports__note">{{ '@'.$persona['username'] }} · {{ $persona['posts'] }} posts · {{ $persona['comments'] }} comments{{ $persona['created'] !== null ? ' · created '.$persona['created'].($persona['createdBy'] !== null ? ' by '.$persona['createdBy'] : '') : '' }}</span>
                </div>
                <p class="rg-admin-persona__lock"><x-admin.ui.icon name="lock" :size="12" />Status can’t be edited here. Use a moderation action in Users.</p>
                <div class="rg-admin-persona__links">
                    @unless ($gone)
                        <x-admin.ui.button size="sm" trailing-icon="arrow-up-right" :href="$persona['profileUrl']" target="_blank" rel="noopener">View public profile</x-admin.ui.button>
                    @endunless
                    @if ($persona['usersUrl'] !== null)
                        <x-admin.ui.button size="sm" icon="users" :href="$persona['usersUrl']">Moderate in Users</x-admin.ui.button>
                    @endif
                </div>
            @endif
            <dl class="rg-admin-detail-list">
                <div class="rg-admin-detail-row">
                    <x-admin.ui.icon name="log-in" class="rg-admin-detail-row__icon" />
                    <dt class="rg-admin-detail-row__label">Sign-in</dt>
                    <dd class="rg-admin-detail-row__value">Never signs in</dd>
                </div>
                <div class="rg-admin-detail-row">
                    <x-admin.ui.icon name="mail" class="rg-admin-detail-row__icon" />
                    <dt class="rg-admin-detail-row__label">Email</dt>
                    <dd class="rg-admin-detail-row__value">Nothing is ever sent</dd>
                </div>
                <div class="rg-admin-detail-row">
                    <x-admin.ui.icon name="shield-check" class="rg-admin-detail-row__icon" />
                    <dt class="rg-admin-detail-row__label">Admin access</dt>
                    <dd class="rg-admin-detail-row__value">None</dd>
                </div>
            </dl>
        </section>
    </form>

    <x-slot:footer x-data="{ get changes() { return rgAdminEditorChanges({{ $draft }}, $wire.saved) } }">
        <span class="rg-admin-editor-drawer__note">
            <span x-show="changes > 0" x-cloak class="rg-admin-editor-drawer__unsaved" x-text="`${changes} unsaved ${changes === 1 ? 'change' : 'changes'}`"></span>
            <span x-show="changes === 0">{{ $creating ? 'Creates a regular account. Nothing is published until a plan uses it.' : 'Changes apply to what it writes from now on.' }}</span>
        </span>
        <x-admin.ui.button x-on:click="dismiss()">Cancel</x-admin.ui.button>
        <x-admin.ui.button
            variant="primary"
            type="submit"
            form="rg-admin-persona-editor-form"
            x-bind:disabled="$wire.stale || $wire.gone{{ $creating ? '' : ' || changes === 0' }}"
            wire:loading.attr="disabled"
            wire:target="save,photo"
        >{{ $creating ? 'Create persona' : 'Save persona' }}</x-admin.ui.button>
    </x-slot:footer>
</x-admin.ui.drawer>
