<?php

namespace App\View\Components;

use App\Models\User;
use App\Queries\Users\UnconfirmedAccountsQuery;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * What an account that has not confirmed its address reads on every page
 * (docs/architecture/user-lifecycle.md, "Unconfirmed accounts"): that it may
 * not post, comment, vote or follow until it does, where the link went, that
 * it is deleted a week after signing up otherwise — and a button for a new
 * link. Renders nothing for guests, confirmed accounts, Google and Facebook
 * sign-ins, staff, Personas and sanctioned accounts, which read their own
 * notice.
 */
final class AccountConfirmationNotice extends Component
{
    public function render(): View|string
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->needsAddressConfirmation()) {
            return '';
        }

        return view('components.account-confirmation-notice', [
            // Only an account that never confirmed an address is removed: one
            // that has changed a confirmed address is not told it will be.
            'notice' => app(UnconfirmedAccountsQuery::class)->isUnconfirmed($user)
                ? __('ui.account_confirmation.notice', ['email' => (string) $user->email, 'days' => UnconfirmedAccountsQuery::GRACE_DAYS])
                : __('ui.account_confirmation.notice_changed_address', ['email' => (string) $user->email]),
            'sent' => session('status') === 'verification-link-sent',
            'wait' => session('verification_wait'),
        ]);
    }
}
