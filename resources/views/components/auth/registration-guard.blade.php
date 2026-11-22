{{--
    The hidden fields every sign-up form carries for ScreenRegistrationAction:
    when the form was drawn, and a field people never see, reach or have
    filled in for them. No id and no label, so it stays out of what the page
    offers to a keyboard, a screen reader or a password manager.
--}}
@use('App\Actions\Auth\ScreenRegistrationAction')
@use('App\Support\Auth\RegistrationFormStamp')

<input
    type="hidden"
    name="{{ RegistrationFormStamp::FIELD }}"
    value="{{ app(RegistrationFormStamp::class)->issue(old(RegistrationFormStamp::FIELD)) }}"
>
<div class="sr-only" aria-hidden="true">
    <input type="text" name="{{ ScreenRegistrationAction::HONEYPOT_FIELD }}" value="" tabindex="-1" autocomplete="off">
</div>
