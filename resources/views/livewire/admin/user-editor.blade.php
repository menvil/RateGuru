@php
    /*
     * A person's editor in the drawer over Users (UserEditor, OVL-02 at the
     * editor's width), in the sections of the person's page it replaced:
     * Profile, Role & trust and Password — the draft, saved from the footer —
     * then Status with the moderation actions that change it, Account, and
     * Moderation history (DOM-02). Cancel, Escape and the scrim close it.
     *
     * The footer counts what is unsaved from the draft against `saved`
     * (filament.pages.editor.script, on the list), and the browser asks
     * before the page is left with any. A Persona's account is drawn
     * read-only, with no Password at all, and leads to its editor in Content
     * Studio for whoever may manage Personas. Confirmations — a change of
     * role, a sanction — open over the drawer.
     */
    $draft = '{ name: $wire.name, username: $wire.username, email: $wire.email, role: $wire.role, trust: $wire.trust, password: $wire.password }';
@endphp

<x-admin.ui.drawer
    id="rg-admin-user-editor"
    editor
    :title="$person['username']"
    :subtitle="$person['name'].($person['joined'] !== null ? ' · Joined '.$person['joined'] : '')"
    x-on:dismiss="$wire.dispatch('close-user')"
    x-on:beforeunload.window="if (rgAdminEditorChanges({{ $draft }}, $wire.saved) > 0) { $event.preventDefault(); $event.returnValue = '' }"
>
    <x-slot:leading>
        <span class="rg-admin-avatar rg-admin-avatar--md">{{ $person['initials'] }}</span>
    </x-slot:leading>

    <form id="rg-admin-user-editor-form" class="rg-admin-editor-drawer" wire:submit="save" novalidate>
        <dl class="rg-admin-stats rg-admin-editor-drawer__stats">
            <div class="rg-admin-stat">
                <dt class="rg-admin-stat__label">Posts</dt>
                <dd class="rg-admin-stat__value">{{ $person['posts'] }}</dd>
            </div>
            <div class="rg-admin-stat">
                <dt class="rg-admin-stat__label">Comments</dt>
                <dd class="rg-admin-stat__value">{{ $person['comments'] }}</dd>
            </div>
            <div class="rg-admin-stat">
                <dt class="rg-admin-stat__label">Reports</dt>
                <dd class="rg-admin-stat__value">{{ $person['reports'] }}</dd>
            </div>
        </dl>

        @if ($isPersona)
            <section class="rg-admin-editor-drawer__section" aria-labelledby="rg-admin-user-editor-profile">
                <div class="rg-admin-section-label"><h3 id="rg-admin-user-editor-profile" class="rg-admin-section-label__text">Profile</h3></div>
                <div class="rg-admin-user__fields">
                    <x-admin.ui.text-field label="Name" id="rg-admin-user-name" :value="$saved['name']" disabled />
                    <x-admin.ui.text-field label="Username" id="rg-admin-user-username" icon="at-sign" :value="$saved['username']" :hint="'Public as @'.$saved['username'].'.'" disabled />
                    <x-admin.ui.text-field label="Email" id="rg-admin-user-email" type="email" icon="mail" class="rg-admin-user__wide" :value="$saved['email']" hint="A technical address: nothing is ever sent to it, and it signs nobody in." disabled />
                </div>
            </section>

            <section class="rg-admin-editor-drawer__section" aria-labelledby="rg-admin-user-editor-role">
                <div class="rg-admin-section-label"><h3 id="rg-admin-user-editor-role" class="rg-admin-section-label__text">Role &amp; trust</h3></div>
                <div class="rg-admin-field">
                    <span class="rg-admin-label">Role</span>
                    <x-admin.ui.badge tone="neutral" class="rg-admin-user__role">{{ $roleLabel }}</x-admin.ui.badge>
                    <p class="rg-admin-hint">{{ $roleHelp['persona'] }}</p>
                </div>
                <div class="rg-admin-field">
                    <span class="rg-admin-label">Trust</span>
                    <p class="rg-admin-hint">{{ $trusts[$saved['trust']]['label'] }}</p>
                </div>
                <p class="rg-admin-user__lock"><x-admin.ui.icon name="lock" :size="12" />A persona’s account can’t be edited here.</p>
                @if ($personaEditorUrl !== null)
                    <x-admin.ui.button size="sm" icon="pencil" class="rg-admin-user__role" :href="$personaEditorUrl">Edit in Content Studio</x-admin.ui.button>
                @endif
            </section>
        @else
            <section class="rg-admin-editor-drawer__section" aria-labelledby="rg-admin-user-editor-profile">
                <div class="rg-admin-section-label"><h3 id="rg-admin-user-editor-profile" class="rg-admin-section-label__text">Profile</h3></div>
                <p class="rg-admin-hint">Public identity and contact address.</p>
                <div class="rg-admin-user__fields">
                    <x-admin.ui.text-field label="Name" id="rg-admin-user-name" :value="$name" placeholder="Full name" :error="$errors->first('name') ?: null" wire:model="name" />
                    <x-admin.ui.text-field
                        label="Username"
                        id="rg-admin-user-username"
                        icon="at-sign"
                        :value="$username"
                        :hint="'Public as @'.$username.'. Old profile links stop working after a change.'"
                        :error="$errors->first('username') ?: null"
                        wire:model="username"
                    />
                    <x-admin.ui.text-field
                        label="Email"
                        id="rg-admin-user-email"
                        type="email"
                        icon="mail"
                        class="rg-admin-user__wide"
                        :value="$email"
                        hint="Used for sign-in links and notices. A new address has to be confirmed again."
                        :error="$errors->first('email') ?: null"
                        wire:model="email"
                    />
                </div>
            </section>

            <section class="rg-admin-editor-drawer__section" aria-labelledby="rg-admin-user-editor-role">
                <div class="rg-admin-section-label"><h3 id="rg-admin-user-editor-role" class="rg-admin-section-label__text">Role &amp; trust</h3></div>
                <p class="rg-admin-hint">Role sets admin permissions. Trust sets whether their posts wait for moderation.</p>
                <div class="rg-admin-field">
                    <span class="rg-admin-label" id="rg-admin-user-role-label">Role</span>
                    <x-admin.ui.segmented label="Role" class="rg-admin-user__role" :options="$roles" :value="$role" wire:model="role" />
                    <p class="rg-admin-hint" x-text="@js($roleHelp)[$wire.role] ?? ''">{{ $roleHelp[$role] ?? '' }}</p>
                </div>
                <div class="rg-admin-field">
                    <span class="rg-admin-label">Trust</span>
                    <x-admin.ui.radio-cards label="Trust" :options="$trusts" :value="$trust" wire:model="trust" />
                </div>
            </section>

            <section class="rg-admin-editor-drawer__section" aria-labelledby="rg-admin-user-editor-password">
                <div class="rg-admin-section-label"><h3 id="rg-admin-user-editor-password" class="rg-admin-section-label__text">Password</h3></div>
                <p class="rg-admin-hint">Leave empty to keep the current password.</p>
                <div class="rg-admin-user__password" x-data="{ shown: false }">
                    <x-admin.ui.text-field
                        label="New password"
                        id="rg-admin-user-password"
                        type="password"
                        icon="key-round"
                        :value="$password"
                        autocomplete="new-password"
                        :error="$errors->first('password') ?: null"
                        x-bind:type="shown ? 'text' : 'password'"
                        wire:model="password"
                    />
                    <x-admin.ui.button variant="ghost" size="sm" class="rg-admin-user__reveal" x-on:click="shown = ! shown" x-bind:aria-pressed="shown ? 'true' : 'false'" aria-controls="rg-admin-user-password">
                        <span x-text="shown ? 'Hide' : 'Show'">Show</span>
                    </x-admin.ui.button>
                </div>
            </section>
        @endif

        <section class="rg-admin-editor-drawer__section" aria-labelledby="rg-admin-user-editor-status">
            <div class="rg-admin-section-label"><h3 id="rg-admin-user-editor-status" class="rg-admin-section-label__text">Status</h3></div>
            <div class="rg-admin-user__status">
                <x-admin.ui.badge :tone="$person['status']['tone']" :dot="$person['status']['dot']">{{ $person['status']['label'] }}</x-admin.ui.badge>
                <span class="rg-admin-reports__note">{{ $person['since'] }}</span>
            </div>
            @if (filled($person['reason']))
                <blockquote class="rg-admin-reports__message">“{{ $person['reason'] }}”</blockquote>
            @endif
            <p class="rg-admin-user__lock"><x-admin.ui.icon name="lock" :size="12" />Status can’t be edited here. Use a moderation action.</p>
            <div class="rg-admin-reports__actions">
                @if ($options['restore'])
                    <x-admin.ui.button size="sm" variant="primary" icon="rotate-ccw" wire:click="restoreAccess({{ $userId }})">Restore access</x-admin.ui.button>
                @endif
                @if ($options['trust']['available'])
                    <x-admin.ui.button size="sm" icon="shield-check" wire:click="askToSanction('trust', {{ $userId }})">Mark trusted</x-admin.ui.button>
                @endif
                @if ($options['limit']['available'])
                    <x-admin.ui.button size="sm" icon="shield-alert" wire:click="askToSanction('limit', {{ $userId }})">Limit</x-admin.ui.button>
                @endif
                @if ($options['shadowban']['available'])
                    <x-admin.ui.button size="sm" icon="eye-off" wire:click="askToSanction('shadowban', {{ $userId }})">Shadowban</x-admin.ui.button>
                @endif
                @if ($options['ban']['available'])
                    <x-admin.ui.button size="sm" icon="ban" wire:click="askToSanction('ban', {{ $userId }})">Ban</x-admin.ui.button>
                @endif
            </div>
        </section>

        <section class="rg-admin-editor-drawer__section" aria-labelledby="rg-admin-user-editor-account">
            <div class="rg-admin-section-label"><h3 id="rg-admin-user-editor-account" class="rg-admin-section-label__text">Account</h3></div>
            <dl class="rg-admin-detail-list">
                <div class="rg-admin-detail-row">
                    <x-admin.ui.icon name="log-in" class="rg-admin-detail-row__icon" />
                    <dt class="rg-admin-detail-row__label">Sign-in</dt>
                    <dd class="rg-admin-detail-row__value">{{ $person['signIn'] }}</dd>
                </div>
                <div class="rg-admin-detail-row">
                    <x-admin.ui.icon name="languages" class="rg-admin-detail-row__icon" />
                    <dt class="rg-admin-detail-row__label">Language</dt>
                    <dd class="rg-admin-detail-row__value">{{ $person['language'] }}</dd>
                </div>
                <div class="rg-admin-detail-row">
                    <x-admin.ui.icon name="calendar" class="rg-admin-detail-row__icon" />
                    <dt class="rg-admin-detail-row__label">Joined</dt>
                    <dd class="rg-admin-detail-row__value">{{ $person['joined'] ?? '—' }}</dd>
                </div>
                <div class="rg-admin-detail-row">
                    <x-admin.ui.icon name="image" class="rg-admin-detail-row__icon" />
                    <dt class="rg-admin-detail-row__label">Posts</dt>
                    <dd class="rg-admin-detail-row__value">{{ $person['postsBreakdown'] }}</dd>
                </div>
            </dl>
        </section>

        <section class="rg-admin-editor-drawer__section" aria-labelledby="rg-admin-user-editor-history">
            <div class="rg-admin-section-label"><h3 id="rg-admin-user-editor-history" class="rg-admin-section-label__text">Moderation history</h3></div>
            <div class="rg-admin-user__history">
                @foreach ($history as $item)
                    <x-admin.ui.timeline-item :icon="$item['icon']" :tone="$item['tone']" :title="$item['title']" :time="$item['time']" :last="$loop->last">
                        @if ($item['body'] !== null)
                            <span class="rg-admin-reports__note">{{ $item['body'] }}</span>
                        @endif
                    </x-admin.ui.timeline-item>
                @endforeach
            </div>
        </section>

        @if ($roleChange !== null)
            <x-admin.ui.confirm-dialog
                id="rg-admin-user-role-confirm"
                tone="warning"
                icon="shield-alert"
                :title="'Change role to '.$roleChange['to'].'?'"
                wire:key="confirm-role-{{ $role }}"
                x-on:dismiss.stop="$wire.closeRoleConfirmation()"
            >
                <p>{{ $roleChange['help'] }} The change applies at their next page load.</p>
                <x-slot:details>
                    <div class="rg-admin-dialog-subject">
                        <span class="rg-admin-dialog-subject__icon" aria-hidden="true"><x-admin.ui.icon name="user" :size="16" /></span>
                        <span class="rg-admin-dialog-subject__text">
                            <span class="rg-admin-dialog-subject__title">{{ $username }}</span>
                            <span class="rg-admin-dialog-subject__meta">{{ $roleChange['from'] }} → {{ $roleChange['to'] }}</span>
                        </span>
                    </div>
                </x-slot:details>
                <x-slot:actions>
                    <x-admin.ui.button x-on:click="dismiss()" autofocus>Cancel</x-admin.ui.button>
                    <x-admin.ui.button variant="primary" wire:click="save" wire:loading.attr="disabled">Change role and save</x-admin.ui.button>
                </x-slot:actions>
            </x-admin.ui.confirm-dialog>
        @endif

        @if ($dialog !== null)
            @include('filament.pages.users.sanction')
        @endif
    </form>

    <x-slot:footer x-data="{ get changes() { return rgAdminEditorChanges({{ $draft }}, $wire.saved) } }">
        <span class="rg-admin-editor-drawer__note">
            <span x-show="changes > 0" x-cloak class="rg-admin-editor-drawer__unsaved" x-text="`${changes} unsaved ${changes === 1 ? 'change' : 'changes'}`"></span>
            <span x-show="changes === 0">{{ $isPersona ? 'A persona is edited in Content Studio.' : 'Status changes only through a moderation action.' }}</span>
        </span>
        <x-admin.ui.button x-on:click="dismiss()">{{ $isPersona ? 'Close' : 'Cancel' }}</x-admin.ui.button>
        @unless ($isPersona)
            <x-admin.ui.button variant="primary" type="submit" form="rg-admin-user-editor-form" x-bind:disabled="changes === 0" wire:loading.attr="disabled" wire:target="save">Save changes</x-admin.ui.button>
        @endunless
    </x-slot:footer>
</x-admin.ui.drawer>
