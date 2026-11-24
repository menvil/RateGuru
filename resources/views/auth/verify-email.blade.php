<x-guest-layout>
    <div class="mb-4 text-sm text-gray-600">
        {{ __('auth.verify_email.intro') }}
    </div>

    @if (session('status') === 'verification-link-expired')
        <div class="mb-4 font-medium text-sm text-red-600" role="alert" data-testid="verification-link-expired">
            {{ __('auth.verify_email.link_expired') }}
        </div>
    @endif

    @if (filled(session('verification_wait')))
        <div class="mb-4 font-medium text-sm text-red-600" role="alert" data-testid="verification-wait">
            {{ session('verification_wait') }}
        </div>
    @endif

    @if (session('status') == 'verification-link-sent')
        <div class="mb-4 font-medium text-sm text-green-600">
            {{ __('auth.verify_email.link_sent') }}
        </div>
    @endif

    <div class="mt-4 flex items-center justify-between">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf

            <div>
                <x-primary-button>
                    {{ __('auth.verify_email.resend') }}
                </x-primary-button>
            </div>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf

            <button type="submit" class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                {{ __('ui.nav.log_out') }}
            </button>
        </form>
    </div>
</x-guest-layout>
