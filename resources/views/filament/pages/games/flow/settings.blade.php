@php
    /*
     * The selected step, as the reference draws it: its sign, name and place,
     * then Settings — who sees it (fixed for Email Capture and Registration,
     * which ask anonymous visitors only), what it does and what it says, each
     * text labelled as its kind of step uses it — or Preview: the step played
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
                                <input type="radio" value="{{ $value }}" wire:model.live="{{ $path }}.settings.advance" />
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
                    <x-admin.ui.switch label="Show score" id="rg-admin-step-score" :checked="(bool) ($step['settings']['show_score'] ?? true)" wire:model.live="{{ $path }}.settings.show_score" />
                    <x-admin.ui.switch label="Show profile" id="rg-admin-step-profile" :checked="(bool) ($step['settings']['show_profile'] ?? true)" wire:model.live="{{ $path }}.settings.show_profile" />
                    <x-admin.ui.switch label="Share buttons" id="rg-admin-step-share" :checked="(bool) ($step['settings']['share_enabled'] ?? true)" wire:model.live="{{ $path }}.settings.share_enabled" />
                    <fieldset class="rg-admin-field rg-admin-games__radio-rows">
                        <legend class="rg-admin-label">Sign-up for anonymous visitors</legend>
                        @foreach ($signups as $signup)
                            <label @class(['rg-admin-games__radio-row', 'rg-admin-games__radio-row--on' => ($step['settings']['signup'] ?? 'inline') === $signup['value']])>
                                <input type="radio" value="{{ $signup['value'] }}" wire:model.live="{{ $path }}.settings.signup" />
                                {{ $signup['label'] }}
                            </label>
                        @endforeach
                        <span class="rg-admin-games__note">Signed-in players are never asked.</span>
                    </fieldset>
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

            @foreach ($stepTexts as $column => $text)
                @php($multiline = in_array($column, ['body', 'notice', 'consent_label'], true) && ! in_array($column, $singleLine[$stepType->value] ?? [], true))
                @php($hint = $hints[$stepType->value][$column] ?? null)
                @if ($multiline)
                    <x-admin.ui.textarea :label="$text['label']" :id="'rg-admin-step-'.$column" :value="$step['texts'][$column] ?? ''" :rows="3" :limit="$textLimits[$column]" :required="in_array($column, $stepRequired, true) ? null : false" :hint="$hint" :error="$errors->first($path.'.texts.'.$column) ?: null" wire:model.live.debounce.400ms="{{ $path }}.texts.{{ $column }}" />
                @else
                    <x-admin.ui.text-field :label="$text['label']" :required="in_array($column, $stepRequired, true) ? null : false" :id="'rg-admin-step-'.$column" :value="$step['texts'][$column] ?? ''" :hint="$hint" :error="$errors->first($path.'.texts.'.$column) ?: null" wire:model.live.debounce.400ms="{{ $path }}.texts.{{ $column }}" />
                @endif
            @endforeach

            @unless ($stepType->value === 'game')
                <div class="rg-admin-games__step-remove">
                    <x-admin.ui.button size="sm" icon="trash-2" wire:click="removeStep({{ $selected }})">Remove step</x-admin.ui.button>
                </div>
            @endunless
        </div>
        @endif
    </div>
@endif
