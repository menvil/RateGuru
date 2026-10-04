{{--
    The one login form. The standalone /login page and the authentication
    modal both render this component; only the surface differs, and with it
    the element ids (both modal forms live in the DOM at once), the error bag
    and the marker fields that bring a modal submission back to its page.
--}}
@use('App\Enums\AuthModalMode')
@use('App\Support\Auth\AuthSurfaceContext')
@use('Illuminate\Support\ViewErrorBag')

@props([
    'surface' => 'page',
    'returnTo' => null,
    'active' => true,
])

@php
    $isModal = $surface === AuthSurfaceContext::MODAL;
    $idPrefix = ($isModal ? 'modal' : 'page').'-login';
    $testId = $isModal ? 'auth-modal-login' : 'login';
    $bag = ($errors ?? new ViewErrorBag)->getBag($isModal ? AuthSurfaceContext::ERROR_BAG : 'default');
@endphp

<form method="POST" action="{{ route('login') }}" data-testid="{{ $testId }}-form" {{ $attributes }}>
    @csrf

    @if ($isModal)
        <input type="hidden" name="{{ AuthSurfaceContext::SURFACE_FIELD }}" value="{{ AuthSurfaceContext::MODAL }}">
        <input type="hidden" name="{{ AuthSurfaceContext::MODE_FIELD }}" value="{{ AuthModalMode::Login->value }}">
        <input type="hidden" name="{{ AuthSurfaceContext::RETURN_FIELD }}" value="{{ $returnTo }}" data-auth-return-input>
    @endif

    <div>
        <x-input-label :for="$idPrefix.'-email'" :value="__('auth.fields.email')" />
        <x-text-input
            :id="$idPrefix.'-email'"
            data-testid="{{ $testId }}-email"
            data-auth-initial-focus
            class="mt-1 block w-full"
            type="email"
            name="email"
            :value="$active ? old('email') : null"
            required
            :autofocus="! $isModal"
            autocomplete="username"
        />
        <x-input-error :messages="$active ? $bag->get('email') : []" class="mt-2" />
    </div>

    <div class="mt-4">
        <x-input-label :for="$idPrefix.'-password'" :value="__('auth.fields.password')" />
        <x-text-input
            :id="$idPrefix.'-password'"
            data-testid="{{ $testId }}-password"
            class="mt-1 block w-full"
            type="password"
            name="password"
            required
            autocomplete="current-password"
        />
        <x-input-error :messages="$active ? $bag->get('password') : []" class="mt-2" />
    </div>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
        <label for="{{ $idPrefix }}-remember" class="inline-flex items-center">
            <input
                id="{{ $idPrefix }}-remember"
                type="checkbox"
                name="remember"
                class="rounded border-rg-border2 bg-rg-card2 text-rg-accent shadow-sm focus:ring-rg-accent"
            >
            <span class="ms-2 text-sm text-rg-text2">{{ __('auth.login.remember') }}</span>
        </label>

        @if (Route::has('password.request'))
            <a
                class="rounded-md text-sm text-rg-muted underline hover:text-rg-text2 focus:outline-none focus:ring-2 focus:ring-rg-accent"
                href="{{ route('password.request') }}"
                data-testid="{{ $testId }}-forgot-password"
            >
                {{ __('auth.login.forgot_password') }}
            </a>
        @endif
    </div>

    <x-primary-button class="mt-6 w-full" data-testid="{{ $testId }}-submit">
        {{ __('auth.login.action') }}
    </x-primary-button>
</form>
