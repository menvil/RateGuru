<?php

namespace App\Actions\Profile;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Self-service account deletion: delegates the entire domain operation to
 * AnonymizeUserAccountAction (irreversible tombstone — the users row is
 * never physically deleted, so nothing cascades and all community
 * contribution survives) and adds the one transport concern the
 * self-service flow needs: logging the current session out, strictly after
 * the anonymization has actually committed.
 */
final class DeleteUserAccountAction
{
    public function __construct(
        private readonly AnonymizeUserAccountAction $anonymize,
    ) {}

    public function execute(User $user): void
    {
        // The wrapper transaction exists so the logout below can be
        // registered via DB::afterCommit(): if a caller ever wraps this
        // action in an outer transaction that later rolls back, the
        // anonymization is undone as a savepoint and the logout callback is
        // discarded with it — the caller is never logged out of an account
        // that, per the DB, was never deleted. With no outer transaction,
        // afterCommit fires immediately after this transaction commits.
        DB::transaction(function () use ($user): void {
            $this->anonymize->execute($user);

            DB::afterCommit(fn () => Auth::guard('web')->logout());
        });
    }
}
