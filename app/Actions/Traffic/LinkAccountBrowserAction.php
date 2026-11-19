<?php

namespace App\Actions\Traffic;

use App\Models\AccountBrowser;
use Carbon\CarbonImmutable;

/**
 * Remembers that a browser belongs to an account — it signed up or signed in
 * there — so a later visit from it, signed out, is known rather than new.
 * Once per browser and account; a second time changes nothing.
 */
final class LinkAccountBrowserAction
{
    public function handle(int $userId, string $browserHash, CarbonImmutable $now): void
    {
        AccountBrowser::query()->insertOrIgnore([
            'user_id' => $userId,
            'visitor_hash' => $browserHash,
            'linked_at' => $now,
        ]);
    }
}
