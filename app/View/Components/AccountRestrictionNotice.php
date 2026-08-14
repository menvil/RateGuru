<?php

namespace App\View\Components;

use App\Enums\UserStatus;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Private restriction notice for the sanctioned authenticated account
 * (docs/architecture/user-lifecycle.md): visible only to the account
 * itself, never next to its public content, and it never exposes the
 * internal moderation reason. Renders nothing for guests, Active users
 * and tombstones (which cannot authenticate at all).
 */
final class AccountRestrictionNotice extends Component
{
    public function render(): View|string
    {
        $noticeKey = match (auth()->user()?->status) {
            UserStatus::Limited => 'ui.account_restriction.limited',
            UserStatus::Banned => 'ui.account_restriction.banned',
            UserStatus::Shadowbanned => 'ui.account_restriction.shadowbanned',
            default => null,
        };

        if ($noticeKey === null) {
            return '';
        }

        return view('components.account-restriction-notice', [
            'noticeKey' => $noticeKey,
        ]);
    }
}
