{{--
    One authentication screen, in the one order it is always shown:

        email/password form  →  primary button  →  "or"  →  Google  →  Facebook  →  switch

    The email/password form stays the primary path; the providers come after
    it, and only those available right now — with none, the "or" goes too.
    A provider switched off while accounts still sign in with it is named
    in a notice on the login side, pointing those people to setting a
    password. The reset-link request is the same screen without providers:
    its form, then the way back to logging in. The standalone pages and
    every mode of the modal are this component, so the two surfaces cannot
    drift apart.

    $socialProviders and $unavailableSocialProviders come from the view
    composer in AppServiceProvider.
--}}
@use('App\Enums\AuthModalMode')
@use('App\Support\Auth\AuthSurfaceContext')
@use('Illuminate\Support\ViewErrorBag')

@props([
    'mode' => 'login',
    'surface' => 'page',
    'returnTo' => null,
    'active' => true,
    'notice' => null,
])

@php
    $mode = AuthModalMode::fromInput($mode);
    $isModal = $surface === AuthSurfaceContext::MODAL;
    $offersProviders = $mode !== AuthModalMode::ForgotPassword && $socialProviders !== [];

    // Login and sign-up point at each other; a reset-link request leads back to login.
    $other = $mode === AuthModalMode::Login ? AuthModalMode::Register : AuthModalMode::Login;
    $socialErrors = $active
        ? ($errors ?? new ViewErrorBag)->getBag($isModal ? AuthSurfaceContext::ERROR_BAG : 'default')->get('social')
        : [];

    $prompt = match ($mode) {
        AuthModalMode::Login => __('auth.prompts.no_account'),
        AuthModalMode::Register => __('auth.prompts.have_account'),
        AuthModalMode::ForgotPassword => __('auth.prompts.remembered_password'),
    };
    $switchLabel = $other === AuthModalMode::Register ? __('auth.register.action') : __('auth.login.action');
    $switchClasses = 'cursor-pointer rounded-md font-semibold text-rg-accent2 hover:text-rg-text focus:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent';
    $setPasswordClasses = 'cursor-pointer rounded-sm font-semibold text-rg-accent2 underline hover:text-rg-text focus:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent';
@endphp

<div {{ $attributes }}>
    @if (filled($notice))
        <x-auth-session-status class="mb-4" :status="$notice" data-testid="auth-notice" />
    @endif

    @if ($mode === AuthModalMode::Login)
        <x-auth.login-form :surface="$surface" :return-to="$returnTo" :active="$active" />
    @elseif ($mode === AuthModalMode::Register)
        <x-auth.register-form :surface="$surface" :return-to="$returnTo" :active="$active" />
    @else
        <x-auth.forgot-password-form :surface="$surface" :return-to="$returnTo" :active="$active" />
    @endif

    @if ($offersProviders)
        <x-auth.divider class="mt-6" />
    @endif

    <x-input-error :messages="$socialErrors" class="mt-4" data-testid="social-error" />

    @if ($offersProviders)
        <x-auth.social-buttons
            class="mt-4"
            :providers="$socialProviders"
            :surface="$surface"
            :mode="$mode->value"
            :return-to="$returnTo"
        />
    @endif

    @if ($mode === AuthModalMode::Login && $unavailableSocialProviders !== [])
        <div
            class="mt-4 space-y-2 rounded-rgControl border border-rg-border bg-rg-card2 px-3 py-2.5 text-xs leading-relaxed text-rg-text2"
            data-testid="social-unavailable-notice"
        >
            @foreach ($unavailableSocialProviders as $provider)
                <p>
                    {{ __('auth.social.unavailable_notice', ['provider' => $provider->label()]) }}
                    @if ($isModal)
                        <button
                            type="button"
                            class="{{ $setPasswordClasses }}"
                            data-testid="social-unavailable-set-password"
                            x-on:click="$dispatch('open-auth-modal', { mode: '{{ AuthModalMode::ForgotPassword->value }}' })"
                        >{{ __('auth.social.unavailable_set_password') }}</button>
                    @else
                        <a
                            class="{{ $setPasswordClasses }}"
                            href="{{ route('password.request') }}"
                            data-testid="social-unavailable-set-password"
                        >{{ __('auth.social.unavailable_set_password') }}</a>
                    @endif
                </p>
            @endforeach
        </div>
    @endif

    <p class="mt-6 text-center text-sm text-rg-muted">
        {{ $prompt }}

        @if ($isModal)
            {{-- Same dialog, other state: no navigation, no reload, no URL change. --}}
            <button
                type="button"
                class="{{ $switchClasses }}"
                data-testid="auth-switch-to-{{ $other->value }}"
                x-on:click="$dispatch('open-auth-modal', { mode: '{{ $other->value }}' })"
            >{{ $switchLabel }}</button>
        @else
            <a
                class="{{ $switchClasses }}"
                data-testid="auth-switch-to-{{ $other->value }}"
                href="{{ route($other === AuthModalMode::Register ? 'register' : 'login') }}"
            >{{ $switchLabel }}</a>
        @endif
    </p>
</div>
