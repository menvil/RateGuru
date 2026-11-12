<div
    data-testid="account-confirmation-notice"
    role="status"
    class="mx-4 mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-lg border border-rg-border bg-rg-surface px-4 py-3 text-sm text-rg-text sm:mx-6 lg:mx-8"
>
    <p class="min-w-0 flex-1 basis-64">{{ __('ui.account_confirmation.notice', ['email' => $email, 'days' => $days]) }}</p>
    @if ($sent)
        <p class="text-rg-text2" data-testid="account-confirmation-sent">{{ __('auth.verify_email.link_sent') }}</p>
    @else
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <x-ui.button type="submit" variant="secondary" size="sm" data-testid="account-confirmation-resend">{{ __('auth.verify_email.resend') }}</x-ui.button>
        </form>
    @endif
</div>
