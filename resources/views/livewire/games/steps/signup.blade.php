{{--
    The sign-up set into the result for an anonymous visitor, in two short
    steps instead of the site's whole form. First the email — or a provider,
    one tap — or logging in instead; then, in the same place, a name and one
    password the visitor can show to check it, which the site's registration
    takes as its confirmation too. It posts to that registration and comes
    back to the game, where the attempt becomes the new account's; a failed
    one comes back on the step its first error belongs to. Once drawn, the
    block is the browser's (wire:ignore): what was typed outlasts the page's
    own requests. A preview signs nobody up.

    The Registration step sets it in under its own title (intro false), with
    its own button for the account and its own log-in link, or none.
--}}
@use('App\Enums\AuthModalMode')
@use('App\Support\Auth\AuthSurfaceContext')
@use('Illuminate\Support\MessageBag')
@php
    $intro ??= true;
    $submitLabel ??= __('games.play.create_account');
    // Null: the site's own "Already have an account? Log in"; empty: none.
    $loginLabel ??= null;
    $failed = old(AuthSurfaceContext::SURFACE_FIELD) === AuthSurfaceContext::EMBEDDED;
    $bag = $failed ? $errors->getBag(AuthSurfaceContext::ERROR_BAG) : new MessageBag;
    $step = $failed && ! $bag->has('email') ? 'details' : 'email';
    $origin = [
        AuthSurfaceContext::SURFACE_FIELD => AuthSurfaceContext::EMBEDDED,
        AuthSurfaceContext::MODE_FIELD => AuthModalMode::Register->value,
        AuthSurfaceContext::RETURN_FIELD => $signup['returnTo'],
    ];
    // The email first, on to the name and password; those then go to the site's registration.
    $onSubmit = "if (step === 'email') { \$event.preventDefault(); step = 'details'; \$wire.signUpStarted('email'); \$nextTick(() => \$refs.name.focus()) }"
        .($preview ? " else { \$event.preventDefault(); \$wire.signUp('register') }" : '');
    // A provider, counted before the browser leaves for it.
    $onProvider = $preview
        ? "\$wire.signUp('register')"
        : '$wire.signUpStarted(link.dataset.provider).finally(() => window.location.assign(link.href))';
@endphp
<form method="POST" action="{{ route('register') }}" @class(['rg-game__signup', 'rg-game__signup--plain' => ! $intro]) data-testid="game-signup"
    wire:ignore wire:key="game-signup-{{ $stepKey }}"
    x-data="{ step: @js($step), email: @js($failed ? (string) old('email', '') : ''), password: '', shown: false }"
    x-bind:data-signup-step="step"
    @if ($failed) x-init="$nextTick(() => $el.scrollIntoView({ block: 'start' }))" @endif
    x-on:submit="{{ $onSubmit }}"
    x-on:click="const link = $event.target.closest('a[data-provider]'); if (link) { $event.preventDefault(); {{ $onProvider }} }"
>
    @csrf
    @foreach ($origin as $field => $value)
        <input type="hidden" name="{{ $field }}" value="{{ $value }}">
    @endforeach

    <div class="rg-game__signup-step" x-show="step === 'email'" @style(['display: none' => $step !== 'email'])>
        @if ($intro)
            <p class="rg-game__signup-title">{{ $texts['title'] }}</p>
            @if (($texts['body'] ?? '') !== '')
                <p class="rg-game__lead">{{ $texts['body'] }}</p>
            @endif
        @endif
        <label class="rg-game__field">
            <span class="rg-game__field-label">{{ __('auth.fields.email') }}</span>
            <input type="email" name="email" class="rg-game__input" autocomplete="email" required x-model="email" x-ref="email" data-testid="game-signup-email">
        </label>
        @foreach ($bag->get('email') as $message)
            <p class="rg-game__error" role="alert">{{ $message }}</p>
        @endforeach
        <button type="submit" class="rg-game__button rg-game__button--primary rg-game__button--wide" data-testid="game-signup-continue">{{ __('games.play.continue_with_email') }}</button>
        @foreach ($signup['providers'] as $provider)
            <a href="{{ route('auth.social.redirect', ['provider' => $provider->value] + $origin) }}" class="rg-game__button rg-game__button--wide" data-provider="{{ $provider->value }}" data-testid="game-signup-{{ $provider->value }}">
                <x-auth.social-provider-icon :provider="$provider" />
                {{ __('games.play.continue_with', ['provider' => $provider->label()]) }}
            </a>
        @endforeach
        @if ($loginLabel === null)
            <p class="rg-game__signup-login">
                {{ __('auth.prompts.have_account') }}
                <button type="button" class="rg-game__link" wire:click="signUp('login')" data-testid="game-signup-login">{{ __('games.play.log_in') }}</button>
            </p>
        @elseif ($loginLabel !== '')
            <p class="rg-game__signup-login">
                <button type="button" class="rg-game__link" wire:click="signUp('login')" data-testid="game-signup-login">{{ $loginLabel }}</button>
            </p>
        @endif
    </div>

    <div class="rg-game__signup-step" x-show="step === 'details'" @style(['display: none' => $step !== 'details'])>
        <p class="rg-game__signup-title">{{ __('games.play.signup_almost') }}</p>
        <p class="rg-game__signup-as">
            <span x-text="email">{{ $failed ? old('email') : '' }}</span> ·
            <button type="button" class="rg-game__link" x-on:click="step = 'email'; $nextTick(() => $refs.email.focus())" data-testid="game-signup-change">{{ __('games.play.signup_change') }}</button>
        </p>
        <label class="rg-game__field">
            <span class="rg-game__field-label">{{ __('auth.fields.name') }}</span>
            <input type="text" name="name" class="rg-game__input" autocomplete="name" value="{{ $failed ? old('name') : '' }}" x-ref="name" x-bind:required="step === 'details'" data-testid="game-signup-name">
        </label>
        @foreach ($bag->get('name') as $message)
            <p class="rg-game__error" role="alert">{{ $message }}</p>
        @endforeach
        <div class="rg-game__field">
            <span class="rg-game__signup-password-head">
                <label class="rg-game__field-label" for="game-signup-password">{{ __('auth.fields.password') }}</label>
                <button type="button" class="rg-game__link" x-on:click="shown = ! shown" x-text="shown ? @js(__('games.play.hide_password')) : @js(__('games.play.show_password'))" data-testid="game-signup-show">{{ __('games.play.show_password') }}</button>
            </span>
            <input type="password" id="game-signup-password" name="password" class="rg-game__input" autocomplete="new-password" x-model="password" x-bind:type="shown ? 'text' : 'password'" x-bind:required="step === 'details'" data-testid="game-signup-password">
            {{-- One password, seen when the visitor wants to check it: it is its own confirmation. --}}
            <input type="hidden" name="password_confirmation" x-bind:value="password">
        </div>
        @foreach ([...$bag->get('password'), ...$bag->get('password_confirmation')] as $message)
            <p class="rg-game__error" role="alert">{{ $message }}</p>
        @endforeach
        <button type="submit" class="rg-game__button rg-game__button--primary rg-game__button--wide" data-testid="game-signup-submit">{{ $submitLabel }}</button>
    </div>
</form>
