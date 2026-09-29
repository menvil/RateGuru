@php
    // An account created through Google or Facebook has no password to type;
    // its own email address confirms the deletion instead.
    $confirmsWithPassword = $user->hasPassword();
@endphp

<section class="space-y-6" x-data="{ deleteOpen: @js($errors->userDeletion->isNotEmpty()) }">
    <header>
        <h2 class="text-lg font-semibold text-rg-text">
            {{ __('Delete Account') }}
        </h2>

        <p class="mt-1 text-sm text-rg-muted">
            {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Before deleting your account, please download any data or information that you wish to retain.') }}
        </p>
    </header>

    <x-danger-button type="button" data-testid="delete-account-open" x-on:click="deleteOpen = true">
        {{ __('Delete Account') }}
    </x-danger-button>

    <x-ui.modal
        :title="__('Are you sure you want to delete your account?')"
        state="deleteOpen"
        size="md"
        trap-focus
        autofocus
        close-on-escape
        below-header
        data-testid="delete-account-modal"
    >
        <form method="post" action="{{ route('profile.destroy') }}" id="delete-account-form" data-testid="delete-account-form">
            @csrf
            @method('delete')

            @if ($confirmsWithPassword)
                <p class="text-sm text-rg-muted">
                    {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.') }}
                </p>

                <div class="mt-4">
                    <x-input-label for="delete_account_password" value="{{ __('Password') }}" class="sr-only" />

                    <x-ui.input
                        id="delete_account_password"
                        name="password"
                        type="password"
                        autocomplete="current-password"
                        autofocus
                        placeholder="{{ __('Password') }}"
                        data-testid="delete-account-password"
                        :error="$errors->userDeletion->has('password')"
                    />

                    <x-input-error :messages="$errors->userDeletion->get('password')" class="mt-2" />
                </div>
            @else
                <p class="text-sm text-rg-muted">
                    {{ __('profile.delete.confirm_with_email', ['email' => $user->email]) }}
                </p>

                <div class="mt-4">
                    <x-input-label for="delete_account_email" value="{{ __('Email') }}" class="sr-only" />

                    <x-ui.input
                        id="delete_account_email"
                        name="email"
                        type="email"
                        autocomplete="off"
                        autofocus
                        placeholder="{{ $user->email }}"
                        data-testid="delete-account-email"
                        :error="$errors->userDeletion->has('email')"
                    />

                    <x-input-error :messages="$errors->userDeletion->get('email')" class="mt-2" />
                </div>
            @endif
        </form>

        <x-slot:footer>
            <x-secondary-button x-on:click="deleteOpen = false" data-testid="delete-account-cancel">
                {{ __('Cancel') }}
            </x-secondary-button>

            <x-danger-button form="delete-account-form" data-testid="delete-account-confirm">
                {{ __('Delete Account') }}
            </x-danger-button>
        </x-slot:footer>
    </x-ui.modal>
</section>
