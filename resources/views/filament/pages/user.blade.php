@php
    /*
     * A person's page (EditUser). The header band: who they are and where
     * they stand, their posts, comments and the reports on their content.
     * Left, the draft saved from the top bar: Profile, Role & trust,
     * Password. Right, what only the moderation actions change: their
     * status with those actions, their account, and the moderation of them
     * and of their content (DOM-02). The browser counts the unsaved changes
     * from the draft against `saved` (filament.pages.editor.script).
     *
     * A Persona's page draws the same account read-only, with no Password
     * card at all: nothing on it is a draft, so the top bar never has a
     * change to save.
     */
    $draft = '{ name: $wire.name, username: $wire.username, email: $wire.email, role: $wire.role, trust: $wire.trust, password: $wire.password }';
@endphp

<div class="rg-admin rg-admin-screen" x-data x-effect="$store.adminEditor.changes = rgAdminEditorChanges({{ $draft }}, $wire.saved)">
    @include('filament.pages.editor.script')

    <section class="rg-admin-page-header rg-admin-user__header" aria-labelledby="rg-admin-user-title">
        <div class="rg-admin-user__identity">
            <span class="rg-admin-avatar rg-admin-avatar--lg" aria-hidden="true">{{ $person['initials'] }}</span>
            <div class="rg-admin-page-header__text">
                <div class="rg-admin-user__title-row">
                    <h1 id="rg-admin-user-title" class="rg-admin-page-header__title">{{ $person['username'] }}</h1>
                    <x-admin.ui.badge :tone="$person['status']['tone']" :dot="$person['status']['dot']">{{ $person['status']['label'] }}</x-admin.ui.badge>
                </div>
                <p class="rg-admin-page-header__description rg-admin-user__meta">
                    <span>{{ $person['name'] }}</span>
                    @if ($person['joined'] !== null)
                        <span>· Joined {{ $person['joined'] }}</span>
                    @endif
                </p>
            </div>
        </div>
        <dl class="rg-admin-stats">
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
    </section>

    <div class="rg-admin-screen__body">
        <div class="rg-admin-user__columns">
            <div class="rg-admin-user__main">
                @if ($isPersona)
                    <x-admin.ui.card title="Profile" description="Public identity and the technical address the account is known by.">
                        <div class="rg-admin-user__fields">
                            <x-admin.ui.text-field label="Name" id="rg-admin-user-name" :value="$saved['name']" disabled />
                            <x-admin.ui.text-field label="Username" id="rg-admin-user-username" icon="at-sign" :value="$saved['username']" :hint="'Public as @'.$saved['username'].'.'" disabled />
                            <x-admin.ui.text-field label="Email" id="rg-admin-user-email" type="email" icon="mail" class="rg-admin-user__wide" :value="$saved['email']" hint="A technical address: nothing is ever sent to it, and it signs nobody in." disabled />
                        </div>
                    </x-admin.ui.card>

                    <x-admin.ui.card title="Role & trust" description="Role sets admin permissions. Trust sets whether their posts wait for moderation.">
                        <div class="rg-admin-user__stack">
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
                        </div>
                    </x-admin.ui.card>
                @else
                    <x-admin.ui.card title="Profile" description="Public identity and contact address.">
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
                    </x-admin.ui.card>

                    <x-admin.ui.card title="Role & trust" description="Role sets admin permissions. Trust sets whether their posts wait for moderation.">
                        <div class="rg-admin-user__stack">
                            <div class="rg-admin-field">
                                <span class="rg-admin-label" id="rg-admin-user-role-label">Role</span>
                                <x-admin.ui.segmented label="Role" class="rg-admin-user__role" :options="$roles" :value="$role" wire:model="role" />
                                <p class="rg-admin-hint" x-text="@js($roleHelp)[$wire.role] ?? ''">{{ $roleHelp[$role] ?? '' }}</p>
                            </div>
                            <div class="rg-admin-field">
                                <span class="rg-admin-label">Trust</span>
                                <x-admin.ui.radio-cards label="Trust" :options="$trusts" :value="$trust" wire:model="trust" />
                            </div>
                        </div>
                    </x-admin.ui.card>

                    <x-admin.ui.card title="Password" description="Leave empty to keep the current password.">
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
                    </x-admin.ui.card>
                @endif
            </div>

            <aside class="rg-admin-user__side">
                <x-admin.ui.card flush>
                    <div class="rg-admin-card__body rg-admin-user__section">
                        <div class="rg-admin-section-label"><h2 class="rg-admin-section-label__text">Status</h2></div>
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
                    </div>

                    <div class="rg-admin-card__body rg-admin-user__section">
                        <div class="rg-admin-section-label"><h2 class="rg-admin-section-label__text">Account</h2></div>
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
                    </div>

                    <div class="rg-admin-card__body rg-admin-user__section">
                        <div class="rg-admin-section-label"><h2 class="rg-admin-section-label__text">Moderation history</h2></div>
                        <div class="rg-admin-user__history">
                            @foreach ($history as $item)
                                <x-admin.ui.timeline-item :icon="$item['icon']" :tone="$item['tone']" :title="$item['title']" :time="$item['time']" :last="$loop->last">
                                    @if ($item['body'] !== null)
                                        <span class="rg-admin-reports__note">{{ $item['body'] }}</span>
                                    @endif
                                </x-admin.ui.timeline-item>
                            @endforeach
                        </div>
                    </div>
                </x-admin.ui.card>
            </aside>
        </div>
    </div>

    @if ($roleChange !== null)
        <x-admin.ui.confirm-dialog
            id="rg-admin-user-role-confirm"
            tone="warning"
            icon="shield-alert"
            :title="'Change role to '.$roleChange['to'].'?'"
            wire:key="confirm-role-{{ $role }}"
            x-on:dismiss="$wire.closeRoleConfirmation()"
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
</div>
