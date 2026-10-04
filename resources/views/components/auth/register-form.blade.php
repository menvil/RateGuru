{{--
    The one registration form, shared by the standalone /register page and
    the authentication modal. See login-form for what the surface changes.
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
    $idPrefix = ($isModal ? 'modal' : 'page').'-register';
    $testId = $isModal ? 'auth-modal-register' : 'register';
    $bag = ($errors ?? new ViewErrorBag)->getBag($isModal ? AuthSurfaceContext::ERROR_BAG : 'default');
@endphp

<form method="POST" action="{{ route('register') }}" data-testid="{{ $testId }}-form" {{ $attributes }}>
    @csrf

    @if ($isModal)
        <input type="hidden" name="{{ AuthSurfaceContext::SURFACE_FIELD }}" value="{{ AuthSurfaceContext::MODAL }}">
        <input type="hidden" name="{{ AuthSurfaceContext::MODE_FIELD }}" value="{{ AuthModalMode::Register->value }}">
        <input type="hidden" name="{{ AuthSurfaceContext::RETURN_FIELD }}" value="{{ $returnTo }}" data-auth-return-input>
    @endif

    <div>
        <x-input-label :for="$idPrefix.'-name'" :value="__('auth.fields.name')" />
        <x-text-input
            :id="$idPrefix.'-name'"
            data-testid="{{ $testId }}-name"
            data-auth-initial-focus
            class="mt-1 block w-full"
            type="text"
            name="name"
            :value="$active ? old('name') : null"
            required
            :autofocus="! $isModal"
            autocomplete="name"
        />
        <x-input-error :messages="$active ? $bag->get('name') : []" class="mt-2" />
    </div>

    <div class="mt-4">
        <x-input-label :for="$idPrefix.'-email'" :value="__('auth.fields.email')" />
        <x-text-input
            :id="$idPrefix.'-email'"
            data-testid="{{ $testId }}-email"
            class="mt-1 block w-full"
            type="email"
            name="email"
            :value="$active ? old('email') : null"
            required
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
            autocomplete="new-password"
        />
        <x-input-error :messages="$active ? $bag->get('password') : []" class="mt-2" />
    </div>

    <div class="mt-4">
        <x-input-label :for="$idPrefix.'-password-confirmation'" :value="__('auth.fields.password_confirmation')" />
        <x-text-input
            :id="$idPrefix.'-password-confirmation'"
            data-testid="{{ $testId }}-password-confirmation"
            class="mt-1 block w-full"
            type="password"
            name="password_confirmation"
            required
            autocomplete="new-password"
        />
        <x-input-error :messages="$active ? $bag->get('password_confirmation') : []" class="mt-2" />
    </div>

    <x-primary-button class="mt-6 w-full" data-testid="{{ $testId }}-submit">
        {{ __('auth.register.action') }}
    </x-primary-button>
</form>
