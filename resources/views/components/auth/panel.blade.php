{{--
    One authentication screen, in the one order it is always shown:

        email/password form  →  primary button  →  "or"  →  Google  →  Facebook  →  switch

    The email/password form stays the primary path; the providers come after
    it. The standalone pages and both modes of the modal are this component,
    so the two surfaces cannot drift apart.
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
    $other = $mode === AuthModalMode::Login ? AuthModalMode::Register : AuthModalMode::Login;
    $socialErrors = $active
        ? ($errors ?? new ViewErrorBag)->getBag($isModal ? AuthSurfaceContext::ERROR_BAG : 'default')->get('social')
        : [];

    $prompt = $mode === AuthModalMode::Login ? __('auth.prompts.no_account') : __('auth.prompts.have_account');
    $switchLabel = $mode === AuthModalMode::Login ? __('Sign up') : __('Log in');
    $switchClasses = 'cursor-pointer rounded-md font-semibold text-rg-accent2 hover:text-rg-text focus:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent';
@endphp

<div {{ $attributes }}>
    @if (filled($notice))
        <x-auth-session-status class="mb-4" :status="$notice" data-testid="auth-notice" />
    @endif

    @if ($mode === AuthModalMode::Login)
        <x-auth.login-form :surface="$surface" :return-to="$returnTo" :active="$active" />
    @else
        <x-auth.register-form :surface="$surface" :return-to="$returnTo" :active="$active" />
    @endif

    <x-auth.divider class="mt-6" />

    <x-input-error :messages="$socialErrors" class="mt-4" data-testid="social-error" />

    <x-auth.social-buttons class="mt-4" :surface="$surface" :mode="$mode->value" :return-to="$returnTo" />

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
