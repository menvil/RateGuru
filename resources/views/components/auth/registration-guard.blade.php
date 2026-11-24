{{--
    The hidden fields every sign-up form carries for ScreenRegistrationAction:
    when the form was drawn, and a field people never see, reach or have
    filled in for them. No id and no label, so it stays out of what the page
    offers to a keyboard, a screen reader or a password manager. With Turnstile
    configured, also the place its widget is drawn (resources/js/turnstile.js).
--}}
@use('App\Actions\Auth\ScreenRegistrationAction')
@use('App\Support\Auth\RegistrationFormStamp')
@use('App\Support\Auth\TurnstileVerifier')

<input
    type="hidden"
    name="{{ RegistrationFormStamp::FIELD }}"
    value="{{ app(RegistrationFormStamp::class)->issue(old(RegistrationFormStamp::FIELD)) }}"
>
<div class="sr-only" aria-hidden="true">
    <input type="text" name="{{ ScreenRegistrationAction::HONEYPOT_FIELD }}" value="" tabindex="-1" autocomplete="off">
</div>
@if (app(TurnstileVerifier::class)->isConfigured())
    <div class="mt-4">
        <div
            class="min-h-[65px]"
            data-rg-turnstile
            data-sitekey="{{ config('services.turnstile.site_key') }}"
            data-action="{{ TurnstileVerifier::ACTION }}"
            data-field="{{ TurnstileVerifier::FIELD }}"
            data-testid="registration-challenge"
        ></div>
        <p class="mt-2 text-sm text-rg-danger" role="alert" data-rg-turnstile-error hidden>{{ __('auth.register.javascript_required') }}</p>
        <noscript><p class="mt-2 text-sm text-rg-danger">{{ __('auth.register.javascript_required') }}</p></noscript>
    </div>
@endif
