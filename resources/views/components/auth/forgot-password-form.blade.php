{{--
    The one form that emails a password reset link, shared by the standalone
    /forgot-password page and the authentication modal. See login-form for
    what the surface changes.
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
    $idPrefix = ($isModal ? 'modal' : 'page').'-forgot-password';
    $testId = $isModal ? 'auth-modal-forgot-password' : 'forgot-password';
    $bag = ($errors ?? new ViewErrorBag)->getBag($isModal ? AuthSurfaceContext::ERROR_BAG : 'default');
@endphp

<form method="POST" action="{{ route('password.email') }}" data-testid="{{ $testId }}-form" {{ $attributes }}>
    @csrf

    @if ($isModal)
        <input type="hidden" name="{{ AuthSurfaceContext::SURFACE_FIELD }}" value="{{ AuthSurfaceContext::MODAL }}">
        <input type="hidden" name="{{ AuthSurfaceContext::MODE_FIELD }}" value="{{ AuthModalMode::ForgotPassword->value }}">
        <input type="hidden" name="{{ AuthSurfaceContext::RETURN_FIELD }}" value="{{ $returnTo }}" data-auth-return-input>
    @endif

    <p class="mb-4 text-sm text-rg-muted">
        {{ __('auth.forgot_password.intro') }}
    </p>

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

    <x-primary-button class="mt-6 w-full" data-testid="{{ $testId }}-submit">
        {{ __('auth.forgot_password.action') }}
    </x-primary-button>
</form>
