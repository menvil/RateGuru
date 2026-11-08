@php
    /*
     * The selected step's settings: who sees it — fixed for Email Capture and
     * Registration, which ask anonymous visitors only — what it does, and what
     * it says, each text labelled as its kind of step uses it.
     */
    $path = 'steps.'.$selected;
    $placeholders = [
        'result_preview' => '{matches}, {total}, {score} and {max} become the player’s numbers.',
        'share' => '{profile}, {game}, {score} and {max} become the player’s result.',
    ];
@endphp

@if ($step === null || $stepType === null)
    <x-admin.ui.card>
        <x-admin.ui.empty-state icon="list" title="No step selected">Choose a step to set what it does and says.</x-admin.ui.empty-state>
    </x-admin.ui.card>
@else
    <x-admin.ui.card :title="$stepType->label()" :description="$stepType->description()" wire:key="step-settings-{{ $step['key'] }}">
        <div class="rg-admin-games__form">
            @if ($stepType->fixedAudience() === null)
                <label class="rg-admin-field">
                    <span class="rg-admin-label">Show to</span>
                    <select class="rg-admin-games__select" wire:model.live="{{ $path }}.audience">
                        @foreach ($audiences as $audience)
                            <option value="{{ $audience['value'] }}">{{ $audience['label'] }}</option>
                        @endforeach
                    </select>
                </label>
            @elseif ($stepType->fixedAudience()->value === 'anonymous')
                <p class="rg-admin-games__note">Shown to anonymous visitors only: a signed-in player already has an account and an email.</p>
            @endif

            @switch ($stepType->value)
                @case('landing')
                    <x-admin.ui.switch label="Show the site’s name" id="rg-admin-step-branding" :checked="(bool) ($step['settings']['show_branding'] ?? true)" on-hint="“A game by …” under the button." off-hint="No branding line." wire:model.live="{{ $path }}.settings.show_branding" />
                    <p class="rg-admin-games__note">The landing page shows the game’s cover from General.</p>
                    @break
                @case('game')
                    <label class="rg-admin-field">
                        <span class="rg-admin-label">Next pair</span>
                        <select class="rg-admin-games__select" wire:model.live="{{ $path }}.settings.advance">
                            @foreach ($advances as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <span class="rg-admin-games__note">Automatic moves on after the delay; the button fills up like a timer and can be tapped sooner. By button waits for it.</span>
                    </label>
                    @if (($step['settings']['advance'] ?? 'auto') === 'auto')
                        <label class="rg-admin-field">
                            <span class="rg-admin-label">Delay before the next pair</span>
                            <select class="rg-admin-games__select" wire:model.live="{{ $path }}.settings.advance_seconds">
                                @foreach ($delays as $delay)
                                    <option value="{{ $delay }}">{{ rtrim(rtrim(number_format($delay, 1), '0'), '.') }} s</option>
                                @endforeach
                            </select>
                        </label>
                    @endif
                    <p class="rg-admin-games__note">The rounds come from the content set the version is published with.</p>
                    @break
                @case('result_preview')
                    <x-admin.ui.switch label="Show partial score" id="rg-admin-step-partial" :checked="(bool) ($step['settings']['show_partial'] ?? true)" on-hint="The headline shows the player’s numbers." off-hint="The teaser replaces the headline." wire:model.live="{{ $path }}.settings.show_partial" />
                    @break
                @case('email_capture')
                    <x-admin.ui.switch label="Required to continue" id="rg-admin-step-required" :checked="(bool) ($step['settings']['required'] ?? false)" on-hint="The result waits for an email. Visitors can still leave." off-hint="Visitors can skip it." wire:model.live="{{ $path }}.settings.required" />
                    <x-admin.ui.switch label="Skip when the email is known" id="rg-admin-step-skip-known" :checked="(bool) ($step['settings']['skip_known'] ?? true)" on-hint="Not asked again of a visitor who left one in this game." off-hint="Asked every time." wire:model.live="{{ $path }}.settings.skip_known" />
                    <x-admin.ui.switch label="Marketing consent checkbox" id="rg-admin-step-consent" :checked="(bool) ($step['settings']['consent_enabled'] ?? true)" on-hint="Separate from giving the email, and always unchecked at first." off-hint="No consent is asked." wire:model.live="{{ $path }}.settings.consent_enabled" />
                    <p class="rg-admin-games__note">The email is stored, never sent anything, and it creates no account.</p>
                    @break
                @case('full_result')
                    <x-admin.ui.switch label="Show score" id="rg-admin-step-score" :checked="(bool) ($step['settings']['show_score'] ?? true)" wire:model.live="{{ $path }}.settings.show_score" />
                    <x-admin.ui.switch label="Show profile" id="rg-admin-step-profile" :checked="(bool) ($step['settings']['show_profile'] ?? true)" wire:model.live="{{ $path }}.settings.show_profile" />
                    <x-admin.ui.switch label="Show agreement" id="rg-admin-step-agreement" :checked="(bool) ($step['settings']['show_agreement'] ?? true)" on-hint="Shown only when the content set shows it too." wire:model.live="{{ $path }}.settings.show_agreement" />
                    <x-admin.ui.switch label="Share buttons" id="rg-admin-step-share" :checked="(bool) ($step['settings']['share_enabled'] ?? true)" wire:model.live="{{ $path }}.settings.share_enabled" />
                    <label class="rg-admin-field">
                        <span class="rg-admin-label">Sign-up for anonymous visitors</span>
                        <select class="rg-admin-games__select" wire:model.live="{{ $path }}.settings.signup">
                            @foreach ($signups as $signup)
                                <option value="{{ $signup['value'] }}">{{ $signup['label'] }}</option>
                            @endforeach
                        </select>
                        <span class="rg-admin-games__note">Signed-in players are never asked.</span>
                    </label>
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
                @php($multiline = in_array($column, ['body', 'notice', 'consent_label'], true))
                @if ($multiline)
                    <x-admin.ui.textarea :label="$text['label']" :id="'rg-admin-step-'.$column" :value="$step['texts'][$column] ?? ''" :rows="3" :limit="$textLimits[$column]" :required="$text['required'] ? null : false" :hint="$column === 'body' ? ($placeholders[$stepType->value] ?? null) : null" :error="$errors->first($path.'.texts.'.$column) ?: null" wire:model.live.debounce.400ms="{{ $path }}.texts.{{ $column }}" />
                @else
                    <x-admin.ui.text-field :label="$text['label']" :required="$text['required'] ? null : false" :id="'rg-admin-step-'.$column" :value="$step['texts'][$column] ?? ''" :hint="$column === 'title' ? ($placeholders[$stepType->value] ?? null) : null" :error="$errors->first($path.'.texts.'.$column) ?: null" wire:model.live.debounce.400ms="{{ $path }}.texts.{{ $column }}" />
                @endif
            @endforeach

            @unless ($stepType->value === 'game')
                <x-admin.ui.button variant="ghost" icon="trash-2" wire:click="removeStep({{ $selected }})">Remove step</x-admin.ui.button>
            @endunless
        </div>
    </x-admin.ui.card>
@endif
