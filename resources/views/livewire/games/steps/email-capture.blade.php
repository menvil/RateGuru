{{--
    Email Capture: the email, kept and never sent anything, and a separate
    consent, unticked at first; skippable unless the step requires it.
--}}
@php($required = (bool) ($data['settings']['required'] ?? false))
<section class="rg-game__card" data-testid="game-step-email_capture">
    <h1 class="rg-game__title rg-game__title--sm">{{ $texts['title'] }}</h1>
    @if (($texts['body'] ?? '') !== '')
        <p class="rg-game__lead">{{ $texts['body'] }}</p>
    @endif
    <form class="rg-game__form" wire:submit="submitEmail">
        <label class="rg-game__field">
            <span class="rg-game__field-label">{{ $texts['field_label'] }}</span>
            <input type="email" class="rg-game__input" autocomplete="email" required wire:model="email" data-testid="game-email">
        </label>
        @error('email')
            <p class="rg-game__error" role="alert">{{ $message }}</p>
        @enderror
        @if ((bool) ($data['settings']['consent_enabled'] ?? true) && ($texts['consent_label'] ?? '') !== '')
            <label class="rg-game__check">
                <input type="checkbox" wire:model="consent" data-testid="game-email-consent">
                <span>{{ $texts['consent_label'] }}</span>
            </label>
        @endif
        <button type="submit" class="rg-game__button rg-game__button--primary rg-game__button--wide" wire:loading.attr="disabled" data-testid="game-email-submit">{{ $texts['button_label'] }}</button>
    </form>
    @if ($required)
        <p class="rg-game__note rg-game__note--center">{{ __('games.play.email_required') }}</p>
    @elseif (($texts['secondary_label'] ?? '') !== '')
        <button type="button" class="rg-game__link" wire:click="continue" wire:loading.attr="disabled" data-testid="game-email-skip">{{ $texts['secondary_label'] }}</button>
    @endif
    @if (($texts['notice'] ?? '') !== '')
        <p class="rg-game__note">{{ $texts['notice'] }}</p>
    @endif
</section>
