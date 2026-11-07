<section>
    <header>
        <h2 class="text-lg font-semibold text-rg-text">
            {{ __('profile.information.title') }}
        </h2>

        <p class="mt-1 text-sm text-rg-muted">
            {{ __('profile.information.description') }}
        </p>
    </header>

    <form id="send-verification" method="post" action="{{ route('verification.send') }}">
        @csrf
    </form>

    <form method="post" action="{{ route('profile.update') }}" class="mt-6 space-y-6">
        @csrf
        @method('patch')

        <div>
            <x-input-label for="name" :value="__('auth.fields.name')" />
            <x-ui.input id="name" name="name" type="text" class="mt-1" :value="old('name', $user->name)" required autofocus autocomplete="name" />
            <x-input-error class="mt-2" :messages="$errors->get('name')" />
        </div>

        <div>
            <x-input-label for="username" :value="__('auth.fields.username')" />
            <x-ui.input id="username" name="username" type="text" class="mt-1" :value="old('username', $user->username)" required autocomplete="username" />
            <p class="mt-1 text-xs text-rg-muted">{{ __('profile.username_url_hint', ['username' => old('username', $user->username) ?: 'username']) }}</p>
            <x-input-error class="mt-2" :messages="$errors->get('username')" />
        </div>

        <div>
            <x-input-label for="email" :value="__('auth.fields.email')" />
            <x-ui.input id="email" name="email" type="email" class="mt-1" :value="old('email', $user->email)" required autocomplete="email" />
            <x-input-error class="mt-2" :messages="$errors->get('email')" />

            @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                <div>
                    <p class="mt-2 text-sm text-rg-text2">
                        {{ __('profile.information.email_unverified') }}

                        <button form="send-verification" class="rounded-rgSm text-sm text-rg-accent underline hover:text-rg-text focus:outline-none focus:ring-2 focus:ring-rg-accent">
                            {{ __('profile.information.resend_verification') }}
                        </button>
                    </p>

                    @if (session('status') === 'verification-link-sent')
                        <p class="mt-2 text-sm font-medium text-rg-accent2">
                            {{ __('profile.information.verification_sent') }}
                        </p>
                    @endif
                </div>
            @endif
        </div>

        <div class="flex items-center gap-4">
            <x-ui.button type="submit">{{ __('ui.actions.save') }}</x-ui.button>

            @if (session('status') === 'profile-updated')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 2000)"
                    class="text-sm text-rg-muted"
                >{{ __('ui.actions.saved') }}</p>
            @endif
        </div>
    </form>
</section>
