{{--
    Email Capture: the email, kept and never sent anything, and a separate
    consent, unticked at first; skippable unless the step requires it.
--}}
@php($required = (bool) ($data['settings']['required'] ?? false))
<section class="rg-game__card" data-testid="game-step-email_capture">
    <x-games.headline :text="$shown['title']" size="sm" />
    @if (($texts['body'] ?? '') !== '')
        <p class="rg-game__lead" {{ $shown['body']->dir() }}>{{ $shown['body'] }}</p>
    @endif
    <form class="rg-game__form" wire:submit="submitEmail">
        <label class="rg-game__field">
            <span class="rg-game__field-label" {{ $shown['field_label']->dir() }}>{{ $shown['field_label'] }}</span>
            <input type="email" class="rg-game__input" autocomplete="email" required wire:model="email" data-testid="game-email">
        </label>
        @error('email')
            <p class="rg-game__error" role="alert">{{ $message }}</p>
        @enderror
        @if ((bool) ($data['settings']['consent_enabled'] ?? true) && ($texts['consent_label'] ?? '') !== '')
            <label class="rg-game__check">
                <input type="checkbox" wire:model="consent" data-testid="game-email-consent">
                <span {{ $shown['consent_label']->dir() }}>{{ $shown['consent_label'] }}</span>
            </label>
        @endif
        <button type="submit" class="rg-game__button rg-game__button--primary rg-game__button--wide" wire:loading.attr="disabled" data-testid="game-email-submit">{{ $shown['button_label']->inline() }}</button>
    </form>
    @if ($required)
        <p class="rg-game__note rg-game__note--center">{{ __('games.play.email_required') }}</p>
    @elseif (($texts['secondary_label'] ?? '') !== '')
        <button type="button" class="rg-game__link" wire:click="continue" wire:loading.attr="disabled" data-testid="game-email-skip">{{ $shown['secondary_label']->inline() }}</button>
    @endif
    @if (($texts['notice'] ?? '') !== '')
        <p class="rg-game__note" {{ $shown['notice']->dir() }}>{{ $shown['notice'] }}</p>
    @endif
</section>
