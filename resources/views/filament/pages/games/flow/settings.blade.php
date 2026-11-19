@php
    /*
     * The selected step, as the reference draws it: its sign, name and place,
     * then Settings — who sees it (fixed for Email Capture and Registration,
     * which ask anonymous visitors only), what it does and what it says, each
     * text labelled as its kind of step uses it, then Remove step and Save flow
     * for whoever reached the end of them — or Preview: the step played
     * as a phone shows it, for an anonymous visitor or a logged-in player,
     * light or dark, unsaved edits included.
     */
    $path = 'steps.'.$selected;
    $audienceNotes = ['all' => 'Everyone sees this step.', 'anonymous' => 'Logged-in visitors skip this step.', 'authenticated' => 'Anonymous visitors skip this step.'];
    $visitors = ['anonymous' => 'Anonymous', 'authenticated' => 'Logged in'];
    $viewers = ['anonymous' => ['Anonymous visitors', 'an anonymous visitor'], 'authenticated' => ['Logged-in players', 'a logged-in player']];
    $viewer = $viewers[$previewVisitor] ?? $viewers['anonymous'];
    $placeholders = [
        'result_preview' => '{matches}, {total}, {score} and {max} become the player’s numbers.',
        'share' => '{profile}, {game}, {score} and {max} become the player’s result.',
    ];
    // What a text is for where its column alone doesn't say.
    $hints = [
        'result_preview' => [
            'title' => 'Shown while Show partial score is on. '.$placeholders['result_preview'],
            'body' => 'Under the headline while Show partial score is on. While it’s off, it takes the headline’s place, large and on one line: the longer it is, the smaller it gets.',
        ],
        'share' => ['title' => $placeholders['share'], 'body' => $placeholders['share']],
    ];
    $singleLine = ['result_preview' => ['body']];
@endphp

@if ($step === null || $stepType === null)
    <p class="rg-admin-games__step-empty">Select a step to edit its settings.</p>
@else
    <div class="rg-admin-games__step-panel" wire:key="step-settings-{{ $step['key'] }}">
        <div class="rg-admin-games__step-panel-head">
            <span class="rg-admin-games__step-sign rg-admin-games__step-sign--bordered" aria-hidden="true"><x-admin.ui.icon :name="$stepType->icon()" :size="17" /></span>
            <span class="rg-admin-games__step-text">
                <span class="rg-admin-games__step-panel-name">{{ $stepType->label() }}</span>
                <span class="rg-admin-games__note">{{ $stepPosition }}</span>
            </span>
        </div>
        <div class="rg-admin-games__step-panel-tabs">
            <x-admin.ui.segmented label="Step panel" :options="['settings' => 'Settings', 'preview' => 'Preview']" :icons="['settings' => 'sliders-horizontal', 'preview' => 'smartphone']" :value="$panel" wire:model.live="panel" compact class="rg-admin-games__pane-switch" />
        </div>

        @if ($panel === 'preview')
            <div class="rg-admin-games__step-preview">
                <div class="rg-admin-games__step-preview-controls">
                    <div class="rg-admin-games__step-preview-as">
                        <span class="rg-admin-games__note">Preview as</span>
                        <x-admin.ui.segmented label="Preview as" :options="$visitors" :value="$previewVisitor" wire:model.live="previewVisitor" compact />
                    </div>
                    <div class="rg-admin-games__step-preview-as">
                        <span class="rg-admin-games__note">Theme</span>
                        <x-admin.ui.segmented label="Theme" :options="['dark' => 'Dark', 'light' => 'Light']" value="light" x-model="theme" compact />
                    </div>
                </div>
                @if ($stepSkipped)
                    <x-admin.ui.inline-notice tone="info">{{ $viewer[0] }} skip this step; the preview shows the next one they see.</x-admin.ui.inline-notice>
                @endif
                @include('filament.pages.games.phone', ['src' => $stepPreviewUrl, 'title' => 'Preview of '.$stepType->label(), 'testid' => 'game-step-preview'])
                <p class="rg-admin-games__note rg-admin-games__step-preview-note">
                    @if ($stepType->value === 'game')
                        Round 1 before the pick. Play every round in Preview.
                    @elseif (in_array($stepType->stage()->value, ['after_game', 'exit'], true))
                        A sample result: every pick matched the crowd. The flow as it stands, unsaved edits included.
                    @else
                        As {{ $viewer[1] }} sees it, unsaved edits included.
                    @endif
                </p>
                <x-admin.ui.button size="sm" icon="play" :href="$fullPreviewUrl">Open full preview</x-admin.ui.button>
            </div>
        @else
        <div class="rg-admin-games__step-settings">
            @if ($stepWorth !== [])
                @include('filament.pages.games.flow.worth')
            @endif

            @if ($stepType->fixedAudience() === null)
                <div class="rg-admin-field">
                    <span class="rg-admin-label">Show to</span>
                    <x-admin.ui.segmented label="Show to" :options="collect($audiences)->mapWithKeys(fn (array $audience): array => [$audience['value'] => $audience['label']])->all()" :value="$step['audience'] ?? 'all'" wire:model.live="{{ $path }}.audience" compact class="rg-admin-games__pane-switch" />
                    <span class="rg-admin-games__note">{{ $audienceNotes[$step['audience'] ?? 'all'] ?? '' }}</span>
                </div>
            @elseif ($stepType->fixedAudience()->value === 'anonymous')
                <p class="rg-admin-games__note">Shown to anonymous visitors only: a signed-in player already has an account and an email.</p>
            @endif

            @switch ($stepType->value)
                @case('landing')
                    <x-admin.ui.switch label="Show the site’s name" id="rg-admin-step-branding" :checked="(bool) ($step['settings']['show_branding'] ?? true)" on-hint="“A game by …” under the button." off-hint="No branding line." wire:model.live="{{ $path }}.settings.show_branding" />
                    <p class="rg-admin-games__note">The landing page shows the game’s cover from General.</p>
                    @break
                @case('game')
                    <fieldset class="rg-admin-field rg-admin-games__radio-rows">
                        <legend class="rg-admin-label">Next pair</legend>
                        @foreach ($advances as $value => $label)
                            <label @class(['rg-admin-games__radio-row', 'rg-admin-games__radio-row--on' => ($step['settings']['advance'] ?? 'auto') === $value])>
                                <input type="radio" name="{{ $path }}.settings.advance" value="{{ $value }}" wire:model.live="{{ $path }}.settings.advance" />
                                {{ $label }}
                            </label>
                        @endforeach
                        <span class="rg-admin-games__note">Automatic moves on after the delay; the button fills up like a timer and can be tapped sooner. By button waits for it.</span>
                    </fieldset>
                    @if (($step['settings']['advance'] ?? 'auto') === 'auto')
                        @php($seconds = (float) ($step['settings']['advance_seconds'] ?? 2.5))
                        <div class="rg-admin-field">
                            <span class="rg-admin-label">Delay before the next pair</span>
                            <div class="rg-admin-games__nudge">
                                <x-admin.ui.icon-button icon="minus" label="Shorter delay" size="sm" :disabled="$seconds <= min($delays)" wire:click="nudgeDelay(-1)" />
                                <span class="rg-admin-games__nudge-value" aria-live="polite">{{ rtrim(rtrim(number_format($seconds, 1), '0'), '.') }} s</span>
                                <x-admin.ui.icon-button icon="plus" label="Longer delay" size="sm" :disabled="$seconds >= max($delays)" wire:click="nudgeDelay(1)" />
                            </div>
                        </div>
                    @endif
                    <p class="rg-admin-games__note">The rounds come from the content set the version is published with.</p>
                    @break
                @case('result_preview')
                    <x-admin.ui.switch label="Show partial score" id="rg-admin-step-partial" :checked="(bool) ($step['settings']['show_partial'] ?? true)" on-hint="The headline shows the player’s numbers." off-hint="The teaser takes the headline’s place, on one line; the numbers wait for the result." wire:model.live="{{ $path }}.settings.show_partial" />
                    @break
                @case('email_capture')
                    <x-admin.ui.switch label="Required to continue" id="rg-admin-step-required" :checked="(bool) ($step['settings']['required'] ?? false)" on-hint="The result waits for an email. Visitors can still leave." off-hint="Visitors can skip it." wire:model.live="{{ $path }}.settings.required" />
                    <x-admin.ui.switch label="Don’t ask again on a replay" id="rg-admin-step-skip-known" :checked="(bool) ($step['settings']['skip_known'] ?? true)" on-hint="A visitor who left an email in this game isn’t asked for it again." off-hint="Asked on every play." wire:model.live="{{ $path }}.settings.skip_known" />
                    <x-admin.ui.switch label="Marketing consent checkbox" id="rg-admin-step-consent" :checked="(bool) ($step['settings']['consent_enabled'] ?? true)" on-hint="Separate from giving the email, and always unchecked at first." off-hint="No consent is asked." wire:model.live="{{ $path }}.settings.consent_enabled" />
                    <p class="rg-admin-games__note">The email is stored, never sent anything, and it creates no account.</p>
                    @break
                @case('full_result')
                    @php($onward = $step['settings']['onward'] ?? 'next')
                    <x-admin.ui.switch label="Show score" id="rg-admin-step-score" :checked="(bool) ($step['settings']['show_score'] ?? true)" wire:model.live="{{ $path }}.settings.show_score" />
                    <x-admin.ui.switch label="Show profile" id="rg-admin-step-profile" :checked="(bool) ($step['settings']['show_profile'] ?? true)" wire:model.live="{{ $path }}.settings.show_profile" />
                    <x-admin.ui.switch label="Share buttons" id="rg-admin-step-share" :checked="(bool) ($step['settings']['share_enabled'] ?? true)" on-hint="A row of ways to share the result, whichever way on." wire:model.live="{{ $path }}.settings.share_enabled" />
                    @include('filament.pages.games.flow.choice', [
                        'label' => 'Way on',
                        'options' => ['next' => 'Sign-up and sharing, then the next step', 'post' => 'Go to a post — one you link to, or one from the game', 'feed' => 'Go to the feed', 'page' => 'Go to a page — a category, a tag, a page of this site or another site'],
                        'value' => $onward,
                        'model' => $path.'.settings.onward',
                        'note' => 'Go to a post, the feed or a page send the visitor on into the site; what they do there counts for the goal and for postbacks. That includes signing up: voting asks for an account, and a sign-up within 7 days counts — though not on another site. The share buttons stay on the card; the steps after this one are then never shown.',
                        'testid' => 'game-flow-way-on',
                    ])
                    @if ($onward === 'post')
                        @include('filament.pages.games.flow.post-settings')
                        @include('filament.pages.games.flow.texts', ['columns' => ['secondary_label', 'notice', 'button_label']])
                        @include('filament.pages.games.flow.auto-open', ['hint' => 'The prompt shows for 3 seconds, then the post opens. Players can still tap the button sooner.'])
                    @elseif ($onward === 'page')
                        @include('filament.pages.games.flow.page-settings')
                        @include('filament.pages.games.flow.texts', ['columns' => ['secondary_label', 'notice', 'button_label']])
                    @elseif ($onward === 'feed')
                        @include('filament.pages.games.flow.choice', ['label' => 'Which feed', 'options' => $feedSorts, 'value' => $step['settings']['feed_sort'] ?? '', 'model' => $path.'.settings.feed_sort'])
                        @include('filament.pages.games.flow.texts', ['columns' => ['secondary_label', 'notice', 'button_label']])
                        <p class="rg-admin-games__note">Three votes in the feed within 30 minutes count as Voted in the feed.</p>
                    @else
                        <fieldset class="rg-admin-field rg-admin-games__radio-rows">
                            <legend class="rg-admin-label">Sign-up for anonymous visitors</legend>
                            @foreach ($signups as $signup)
                                <label @class(['rg-admin-games__radio-row', 'rg-admin-games__radio-row--on' => ($step['settings']['signup'] ?? 'inline') === $signup['value']])>
                                    <input type="radio" name="{{ $path }}.settings.signup" value="{{ $signup['value'] }}" wire:model.live="{{ $path }}.settings.signup" />
                                    {{ $signup['label'] }}
                                </label>
                            @endforeach
                            <span class="rg-admin-games__note">Signed-in players are never asked.</span>
                        </fieldset>
                        @if (($step['settings']['signup'] ?? 'inline') === 'inline')
                            @include('filament.pages.games.flow.texts', ['columns' => ['title', 'body']])
                        @endif
                    @endif
                    @break
                @case('feed')
                    @include('filament.pages.games.flow.texts', ['columns' => ['title', 'body']])
                    @include('filament.pages.games.flow.choice', ['label' => 'Which feed', 'options' => $feedSorts, 'value' => $step['settings']['feed_sort'] ?? '', 'model' => $path.'.settings.feed_sort'])
                    @include('filament.pages.games.flow.texts', ['columns' => ['button_label']])
                    <p class="rg-admin-games__note">Three votes in the feed within 30 minutes count as Voted in the feed.</p>
                    @break
                @case('go_to_post')
                    @include('filament.pages.games.flow.texts', ['columns' => ['title', 'body']])
                    @include('filament.pages.games.flow.post-settings')
                    @include('filament.pages.games.flow.texts', ['columns' => ['button_label']])
                    @include('filament.pages.games.flow.auto-open', ['hint' => 'The card shows for 3 seconds, then the post opens. Players can still tap the button sooner.'])
                    @break
                @case('go_to_page')
                    @include('filament.pages.games.flow.texts', ['columns' => ['title']])
                    @include('filament.pages.games.flow.page-settings')
                    @include('filament.pages.games.flow.texts', ['columns' => ['button_label']])
                    @break
                @case('next_game')
                    <label class="rg-admin-field">
                        <span class="rg-admin-label">Next game</span>
                        <select class="rg-admin-games__select" wire:model.live="{{ $path }}.settings.next_game_id">
                            <option value="">Choose a game</option>
                            @foreach ($nextGames as $other)
                                <option value="{{ $other['id'] }}">{{ $other['title'] }} · {{ $other['status'] }}</option>
                            @endforeach
                        </select>
                        <span class="rg-admin-games__note">Skipped while that game isn’t live.</span>
                    </label>
                    @break
            @endswitch

            @unless (in_array($stepType->value, ['full_result', 'feed', 'go_to_post', 'go_to_page'], true))
                @include('filament.pages.games.flow.texts', ['columns' => array_keys($stepTexts)])
            @endunless

            {{-- Where the settings end, the top bar's Save again: the same save, waiting as it waits for an unsaved change. --}}
            <div class="rg-admin-games__step-foot">
                @unless ($stepType->value === 'game')
                    <x-admin.ui.button size="sm" icon="trash-2" wire:click="removeStep({{ $selected }})">Remove step</x-admin.ui.button>
                @endunless
                <x-admin.ui.button size="sm" variant="primary" class="rg-admin-games__step-save" disabled x-bind:disabled="! ($store.adminEditor?.changes > 0) || $store.adminEditor?.blocked" wire:click="save" data-testid="game-flow-step-save">Save flow</x-admin.ui.button>
            </div>
        </div>
        @endif
    </div>
@endif
