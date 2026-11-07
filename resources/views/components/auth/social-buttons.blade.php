{{--
    The Google and Facebook entry points, and nothing else: the divider, the
    error line and the login/sign-up switch belong to the panel that composes
    them. The same links serve both modes — the provider flow decides on its
    own whether the person is signing in or signing up. Only the providers
    that are available right now are passed in (SocialProviderAvailability).
--}}
@use('App\Support\Auth\AuthSurfaceContext')

@props([
    'providers' => [],
    'surface' => 'page',
    'mode' => 'login',
    'returnTo' => null,
])

@php
    $isModal = $surface === AuthSurfaceContext::MODAL;

    // Only the modal tells the provider round trip where it started. The
    // path is a hint for the server, which vets it before trusting it.
    $origin = $isModal
        ? [
            AuthSurfaceContext::SURFACE_FIELD => AuthSurfaceContext::MODAL,
            AuthSurfaceContext::MODE_FIELD => $mode,
            AuthSurfaceContext::RETURN_FIELD => $returnTo,
        ]
        : [];
@endphp

<div {{ $attributes->class(['space-y-3']) }} data-testid="social-buttons">
    @foreach ($providers as $provider)
        <a
            href="{{ route('auth.social.redirect', ['provider' => $provider->value] + $origin) }}"
            class="inline-flex h-[38px] w-full items-center justify-center gap-2 rounded-rgControl border border-rg-border2 bg-rg-card px-4 text-[13px] font-semibold text-rg-text2 transition-colors hover:bg-rg-card2 hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent focus-visible:ring-offset-2 focus-visible:ring-offset-rg-card"
            data-testid="social-{{ $provider->value }}"
            @if ($isModal) data-auth-return-link @endif
        >
            <x-auth.social-provider-icon :provider="$provider" />
            {{ __('auth.social.log_in_with', ['provider' => $provider->label()]) }}
        </a>
    @endforeach
</div>
