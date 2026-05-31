<x-guest-layout>
    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}" data-testid="login-form">
        @csrf

        <!-- Email Address -->
        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" data-testid="login-email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" :value="__('Password')" />

            <x-text-input id="password" class="block mt-1 w-full"
                            data-testid="login-password"
                            type="password"
                            name="password"
                            required autocomplete="current-password" />

            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Remember Me -->
        <div class="block mt-4">
            <label for="remember_me" class="inline-flex items-center">
                <input id="remember_me" type="checkbox" class="rounded border-rg-border2 bg-rg-card2 text-rg-accent shadow-sm focus:ring-rg-accent" name="remember">
                <span class="ms-2 text-sm text-rg-text2">{{ __('Remember me') }}</span>
            </label>
        </div>

        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
            @if (Route::has('register'))
                <a class="rounded-md text-sm font-semibold text-rg-accent2 hover:text-rg-text focus:outline-none focus:ring-2 focus:ring-rg-accent" href="{{ route('register') }}">
                    {{ __('Create account') }}
                </a>
            @endif

            <div class="flex items-center justify-end">
            @if (Route::has('password.request'))
                <a class="underline text-sm text-rg-muted hover:text-rg-text2 rounded-md focus:outline-none focus:ring-2 focus:ring-rg-accent" href="{{ route('password.request') }}">
                    {{ __('Forgot your password?') }}
                </a>
            @endif

            <x-primary-button class="ms-3" data-testid="login-submit">
                {{ __('Log in') }}
            </x-primary-button>
            </div>
        </div>
    </form>
</x-guest-layout>
