<?php

namespace App\Services\Notifications;

use App\Models\Concerns\LocksUsersInOrder;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;

/**
 * The single boundary for database notifications that snapshot User
 * identity (docs/architecture/user-lifecycle.md). A fresh() before
 * notify() still leaves a TOCTOU window against anonymization; this
 * serializes instead: recipient and identity source are locked in the
 * deterministic ascending-id order every user-pair path uses, the
 * notification is constructed ONLY from the fresh locked identity source,
 * and it is persisted while the locks are held.
 *
 * Race semantics — exactly two outcomes:
 * - notifier wins: the row is written under lock; anonymization waits,
 *   then its PR-B cleanup removes the identity-bearing row (actor_id/
 *   author_id keys) — no old PII remains;
 * - anonymization wins: the locked re-read sees Deleted and nothing is
 *   created.
 *
 * Recipient rule mirrors PR-F: every living account (sanctions included)
 * keeps its inbox; only a Deleted tombstone receives nothing. This is not
 * a general notification bus — notifications without identity snapshots
 * do not need it.
 */
final class LifecycleSafeDatabaseNotifier
{
    use LocksUsersInOrder;

    /**
     * @param  callable(User $freshIdentitySource): Notification  $notification
     */
    public function send(int $recipientId, int $identitySourceId, callable $notification): bool
    {
        return DB::transaction(function () use ($recipientId, $identitySourceId, $notification): bool {
            $locked = $this->lockUsersInOrder($recipientId, $identitySourceId);

            $recipient = $locked->get($recipientId);
            $identitySource = $locked->get($identitySourceId);

            if ($recipient === null || $recipient->isTombstoned()) {
                return false;
            }

            if ($identitySource === null || $identitySource->isTombstoned()) {
                return false;
            }

            $recipient->notify($notification($identitySource));

            return true;
        });
    }
}
