{{--
    The social sign-in entry points, shared by the login and registration
    pages and by whatever auth surface comes next (a modal reuses this
    component as-is). "Continue with" on purpose: the provider decides
    whether the person is signing in or signing up.
--}}
@php
    $providers = [
        \App\Enums\SocialProvider::Google,
        \App\Enums\SocialProvider::Facebook,
    ];
@endphp

<div {{ $attributes->class(['space-y-3']) }} data-testid="social-buttons">
    <div class="flex items-center gap-3" aria-hidden="true">
        <span class="h-px flex-1 bg-rg-border"></span>
        <span class="text-xs font-medium uppercase tracking-wide text-rg-muted">{{ __('or') }}</span>
        <span class="h-px flex-1 bg-rg-border"></span>
    </div>

    <x-input-error :messages="$errors->get('social')" />

    @foreach ($providers as $provider)
        <a
            href="{{ route('auth.social.redirect', ['provider' => $provider->value]) }}"
            class="inline-flex h-[38px] w-full items-center justify-center gap-2 rounded-rgControl border border-rg-border2 bg-rg-card px-4 text-[13px] font-semibold text-rg-text2 transition-colors hover:bg-rg-card2 hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent focus-visible:ring-offset-2 focus-visible:ring-offset-rg-card"
            data-testid="social-{{ $provider->value }}"
        >
            <x-auth.social-provider-icon :provider="$provider" />
            {{ __('Continue with :provider', ['provider' => $provider->label()]) }}
        </a>
    @endforeach
</div>
