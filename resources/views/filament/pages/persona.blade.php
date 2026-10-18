@php
    /*
     * One Persona's editor (CreatePersona, EditPersona). The header band with
     * who it is and where it stands. Left, the draft saved from the top bar:
     * Profile — the public face, as on its profile page — Character and
     * Activity, which are Content Studio's own and never shown on the site.
     * Right, the account: its status, which only moderation in Users changes,
     * its public profile, and what it can never do.
     *
     * The browser counts what is unsaved from the draft against `saved`
     * (filament.pages.editor.script) and asks before a page with unsaved
     * changes is left; a chosen photo counts as one change. An editor drawn
     * before somebody else's save, or of a Persona gone meanwhile, holds Save
     * back and says so.
     */
    $draft = '{ displayName: $wire.displayName, username: $wire.username, bio: $wire.bio, shortDescription: $wire.shortDescription, character: $wire.character, speechStyle: $wire.speechStyle, topics: $wire.topics, neverWritesAbout: $wire.neverWritesAbout, emojiFrequency: $wire.emojiFrequency, engagement: $wire.engagement, maxRepliesPerDay: $wire.maxRepliesPerDay, photo: $wire.photo ? \'new\' : \'\' }';
    $blocked = $creating ? 'false' : '$wire.stale || $wire.gone';
    $title = $creating ? 'New persona' : $persona['displayName'];
@endphp

<div
    class="rg-admin rg-admin-screen"
    x-data
    x-effect="$store.adminEditor.changes = rgAdminEditorChanges({{ $draft }}, $wire.saved); $store.adminEditor.blocked = {{ $blocked }}"
    x-on:beforeunload.window="if (rgAdminEditorChanges({{ $draft }}, $wire.saved) > 0) { $event.preventDefault(); $event.returnValue = '' }"
>
    @include('filament.pages.editor.script')

    <section class="rg-admin-page-header rg-admin-persona__header" aria-labelledby="rg-admin-persona-title">
        <div class="rg-admin-persona__identity">
            <span class="rg-admin-avatar rg-admin-avatar--lg" aria-hidden="true">
                @if (! $creating && $persona['avatar'] !== null)
                    <img src="{{ $persona['avatar'] }}" alt="" class="rg-admin-avatar__image" />
                @else
                    {{ $creating ? $avatar['initials'] : $persona['initials'] }}
                @endif
            </span>
            <div class="rg-admin-page-header__text">
                <div class="rg-admin-persona__title-row">
                    <h1 id="rg-admin-persona-title" class="rg-admin-page-header__title">{{ $title !== '' ? $title : 'Persona' }}</h1>
                    @unless ($creating)
                        <x-admin.ui.badge :tone="$persona['status']['tone']" :dot="$persona['status']['dot']">{{ $persona['status']['label'] }}</x-admin.ui.badge>
                    @endunless
                </div>
                <p class="rg-admin-page-header__description rg-admin-persona__meta">
                    @if ($creating)
                        <span>A regular account run by Content Studio. Creating it publishes nothing.</span>
                    @else
                        <span>{{ '@'.$persona['username'] }}</span>
                        @if ($persona['created'] !== null)
                            <span>· Created {{ $persona['created'] }}{{ $persona['createdBy'] !== null ? ' by '.$persona['createdBy'] : '' }}</span>
                        @endif
                    @endif
                </p>
            </div>
        </div>
        @unless ($creating)
            <dl class="rg-admin-stats">
                <div class="rg-admin-stat">
                    <dt class="rg-admin-stat__label">Posts</dt>
                    <dd class="rg-admin-stat__value">{{ $persona['posts'] }}</dd>
                </div>
                <div class="rg-admin-stat">
                    <dt class="rg-admin-stat__label">Comments</dt>
                    <dd class="rg-admin-stat__value">{{ $persona['comments'] }}</dd>
                </div>
            </dl>
        @endunless
    </section>

    <div class="rg-admin-screen__body">
        <div class="rg-admin-persona__columns">
            <div class="rg-admin-persona__main">
                @if (! $creating && $gone)
                    <x-admin.ui.inline-notice tone="warning">
                        This persona can’t be edited any more: its account was deleted, or it is no longer a persona.
                    </x-admin.ui.inline-notice>
                @elseif (! $creating && $stale)
                    <x-admin.ui.inline-notice tone="warning">
                        Someone saved this persona after you opened it. Reload to see their changes; what you typed here is replaced.
                        <x-slot:actions>
                            <x-admin.ui.button size="sm" icon="refresh-cw" wire:click="reload">Reload</x-admin.ui.button>
                        </x-slot:actions>
                    </x-admin.ui.inline-notice>
                @endif

                <x-admin.ui.card title="Profile" description="Public, as on its profile page.">
                    <div class="rg-admin-persona__stack">
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

                        <x-admin.ui.inline-notice tone="info">
                            On the site this is a regular account: its posts, comments and votes count like anyone’s, and its posts wait for moderation.
                        </x-admin.ui.inline-notice>
                    </div>
                </x-admin.ui.card>

                <x-admin.ui.card title="Character" description="Internal. Never shown on the site; the AI that writes as this persona will follow it.">
                    <div class="rg-admin-persona__stack">
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
                    </div>
                </x-admin.ui.card>

                <x-admin.ui.card title="Activity" description="Saved as settings for now: nothing replies on its own yet.">
                    <div class="rg-admin-persona__stack">
                        <div class="rg-admin-field">
                            <span class="rg-admin-label">Engagement</span>
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
                    </div>
                </x-admin.ui.card>
            </div>

            <aside class="rg-admin-persona__side">
                <x-admin.ui.card flush>
                    <div class="rg-admin-card__body rg-admin-persona__section">
                        <div class="rg-admin-section-label"><h2 class="rg-admin-section-label__text">Account</h2></div>
                        @if ($creating)
                            <p class="rg-admin-persona__note">
                                Creating makes a regular account with the Persona role. It starts active, with no password and an address nothing is ever sent to.
                            </p>
                        @else
                            <div class="rg-admin-persona__status">
                                <x-admin.ui.badge :tone="$persona['status']['tone']" :dot="$persona['status']['dot']">{{ $persona['status']['label'] }}</x-admin.ui.badge>
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
                    </div>

                    <div class="rg-admin-card__body rg-admin-persona__section">
                        <div class="rg-admin-section-label"><h2 class="rg-admin-section-label__text">Never</h2></div>
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
                    </div>
                </x-admin.ui.card>
            </aside>
        </div>
    </div>
</div>
