@if (! $user->hasPassword())
    {{-- An account created through Google or Facebook has no password to
         change. It gets one through the password email sent to its own
         address, so only whoever reads that mailbox can set it. --}}
    <section data-testid="set-password-section">
        <header>
            <h2 class="text-lg font-semibold text-rg-text">
                {{ __('profile.password.set_title') }}
            </h2>

            <p class="mt-1 text-sm text-rg-muted">
                {{ __('profile.password.set_description') }}
            </p>
        </header>

        <form method="post" action="{{ route('password.set-link') }}" class="mt-6 space-y-4">
            @csrf

            <x-input-error :messages="$errors->passwordSetLink->get('email')" />

            <div class="flex flex-wrap items-center gap-4">
                <x-ui.button type="submit" data-testid="set-password-send">{{ __('profile.password.set_send') }}</x-ui.button>

                @if (session('status') === 'password-set-link-sent')
                    <p class="text-sm text-rg-good" data-testid="set-password-sent">{{ __('profile.password.set_sent', ['email' => $user->email]) }}</p>
                @endif
            </div>
        </form>
    </section>
@else
<section>
    <header>
        <h2 class="text-lg font-semibold text-rg-text">
            {{ __('profile.password.update_title') }}
        </h2>

        <p class="mt-1 text-sm text-rg-muted">
            {{ __('profile.password.update_description') }}
        </p>
    </header>

    <form method="post" action="{{ route('password.update') }}" class="mt-6 space-y-6">
        @csrf
        @method('put')

        <div>
            <x-input-label for="update_password_current_password" :value="__('profile.password.current')" />
            <x-ui.input id="update_password_current_password" name="current_password" type="password" class="mt-1" autocomplete="current-password" />
            <x-input-error :messages="$errors->updatePassword->get('current_password')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="update_password_password" :value="__('profile.password.new')" />
            <x-ui.input id="update_password_password" name="password" type="password" class="mt-1" autocomplete="new-password" />
            <x-input-error :messages="$errors->updatePassword->get('password')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="update_password_password_confirmation" :value="__('auth.fields.password_confirmation')" />
            <x-ui.input id="update_password_password_confirmation" name="password_confirmation" type="password" class="mt-1" autocomplete="new-password" />
            <x-input-error :messages="$errors->updatePassword->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="flex items-center gap-4">
            <x-ui.button type="submit">{{ __('ui.actions.save') }}</x-ui.button>

            @if (session('status') === 'password-set')
                <p class="text-sm text-rg-good" data-testid="password-set-status">{{ __('profile.password.set_done') }}</p>
            @endif

            @if (session('status') === 'password-updated')
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
@endif
