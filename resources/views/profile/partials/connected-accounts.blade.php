{{--
    The Google and Facebook sign-ins of this account. Connecting runs the
    ordinary provider round trip while signed in; disconnecting asks first
    and is never offered for the account's last way to sign in.
--}}
@use('App\Enums\SocialProvider')
@use('App\Support\Profile\ConnectedAccountsResponse')

<section id="{{ ConnectedAccountsResponse::FRAGMENT }}" class="scroll-mt-24" data-testid="connected-accounts">
    <header>
        <h2 class="text-lg font-semibold text-rg-text">
            {{ __('profile.connected.title') }}
        </h2>

        <p class="mt-1 text-sm text-rg-muted">
            {{ __('profile.connected.description') }}
        </p>
    </header>

    @if (session()->has(ConnectedAccountsResponse::STATUS_KEY))
        <p class="mt-4 text-sm text-rg-good" role="status" data-testid="connected-accounts-status">
            {{ session(ConnectedAccountsResponse::STATUS_KEY) }}
        </p>
    @endif

    <x-input-error
        :messages="$errors->getBag(ConnectedAccountsResponse::ERROR_BAG)->get('social')"
        class="mt-4"
        role="alert"
        data-testid="connected-accounts-error"
    />

    <ul class="mt-6 divide-y divide-rg-border rounded-rgControl border border-rg-border">
        @foreach (SocialProvider::cases() as $provider)
            @php
                $account = $connectedAccounts->accountFor($provider);
            @endphp

            <li
                class="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
                x-data="{ disconnectOpen: false }"
                data-testid="connected-account-{{ $provider->value }}"
                data-connected="{{ $account !== null ? 'true' : 'false' }}"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <x-auth.social-provider-icon :provider="$provider" class="size-5" />

                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-rg-text">{{ $provider->label() }}</p>

                        @if ($account !== null)
                            <p class="truncate text-xs text-rg-text2" data-testid="connected-account-email">
                                {{ $account->provider_email ?? __('profile.connected.no_email') }}
                            </p>

                            @if ($account->created_at !== null)
                                <p class="text-xs text-rg-muted">
                                    {{ __('profile.connected.since', ['date' => $account->created_at->translatedFormat('j M Y')]) }}
                                </p>
                            @endif
                        @else
                            <p class="text-xs text-rg-muted">{{ __('profile.connected.not_connected') }}</p>
                        @endif
                    </div>
                </div>

                @if ($account === null)
                    <form method="post" action="{{ route('profile.connected-accounts.store', ['provider' => $provider->value]) }}">
                        @csrf

                        <x-ui.button type="submit" variant="secondary" size="sm" data-testid="connect-{{ $provider->value }}">
                            {{ __('profile.connected.connect') }}
                        </x-ui.button>
                    </form>
                @elseif ($connectedAccounts->canDisconnect($provider))
                    <x-ui.button variant="secondary" size="sm" x-on:click="disconnectOpen = true" data-testid="disconnect-{{ $provider->value }}">
                        {{ __('profile.connected.disconnect') }}
                    </x-ui.button>

                    <x-ui.modal
                        :title="__('profile.connected.confirm_title', ['provider' => $provider->label()])"
                        state="disconnectOpen"
                        size="md"
                        trap-focus
                        close-on-escape
                        below-header
                        data-testid="disconnect-{{ $provider->value }}-modal"
                    >
                        <p class="text-sm text-rg-muted">
                            {{ __('profile.connected.confirm_body', ['provider' => $provider->label()]) }}
                        </p>

                        @if ($account->provider_email !== null)
                            <p class="mt-3 truncate text-sm font-semibold text-rg-text">{{ $account->provider_email }}</p>
                        @endif

                        <form
                            method="post"
                            action="{{ route('profile.connected-accounts.destroy', ['provider' => $provider->value]) }}"
                            id="disconnect-{{ $provider->value }}-form"
                        >
                            @csrf
                            @method('delete')
                        </form>

                        <x-slot:footer>
                            <x-ui.button variant="secondary" x-on:click="disconnectOpen = false" data-testid="disconnect-{{ $provider->value }}-cancel">
                                {{ __('Cancel') }}
                            </x-ui.button>

                            <x-ui.button type="submit" variant="danger" form="disconnect-{{ $provider->value }}-form" data-testid="disconnect-{{ $provider->value }}-confirm">
                                {{ __('profile.connected.disconnect') }}
                            </x-ui.button>
                        </x-slot:footer>
                    </x-ui.modal>
                @else
                    <p class="max-w-xs text-xs text-rg-muted sm:text-right" data-testid="connected-account-last-method">
                        {{ __('profile.connected.last_method') }}
                    </p>
                @endif
            </li>
        @endforeach
    </ul>
</section>
