<?php

namespace App\Console\Commands;

use App\Actions\Users\PurgeUnconfirmedAccountAction;
use App\Enums\UnconfirmedAccountPurgeOutcome;
use App\Queries\Users\UnconfirmedAccountsQuery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Removes the accounts that signed up with an email address and did not
 * confirm one within a week, with everything they did
 * (docs/architecture/user-lifecycle.md, "Unconfirmed accounts"). The
 * candidate query is a pre-filter: each account is asked again on its locked
 * row by PurgeUnconfirmedAccountAction, so one confirmed meanwhile stays.
 * --dry-run lists who would go without removing anyone.
 */
final class UsersPurgeUnconfirmedCommand extends Command
{
    protected $signature = 'users:purge-unconfirmed
        {--user= : Only process the account with this id}
        {--dry-run : List the accounts that would be removed without removing them}';

    protected $description = 'Remove accounts that did not confirm their email address within a week of signing up, with everything they did.';

    public function handle(UnconfirmedAccountsQuery $accounts, PurgeUnconfirmedAccountAction $purge): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $userId = $this->userIdFilter();

        if ($userId === false) {
            return self::FAILURE;
        }

        $cutoff = UnconfirmedAccountsQuery::cutoff();
        $counts = array_fill_keys(array_map(
            fn (UnconfirmedAccountPurgeOutcome $outcome): string => $outcome->value,
            UnconfirmedAccountPurgeOutcome::cases(),
        ), 0);

        $process = function (int $id, string $label) use (&$counts, $purge, $cutoff, $dryRun): void {
            try {
                $outcome = $purge->handle($id, $cutoff, $dryRun);
            } catch (Throwable $exception) {
                report($exception);
                Log::error('Unconfirmed account purge failed.', ['user_id' => $id, 'exception' => $exception->getMessage()]);
                $outcome = UnconfirmedAccountPurgeOutcome::Failed;
            }

            $counts[$outcome->value]++;
            $this->line(sprintf('user %d%s: %s', $id, $label, $outcome->value));
        };

        if ($userId !== null) {
            $process($userId, '');
        } else {
            $accounts->expired($cutoff)
                ->select(['id', 'username', 'email', 'created_at'])
                ->orderBy('id')
                ->chunkById(100, function ($users) use ($process, $dryRun): void {
                    foreach ($users as $user) {
                        // Who would go is listed by name on a dry run only:
                        // a real one leaves no address behind in the output.
                        $label = $dryRun ? sprintf(' (%s, %s, signed up %s)', $user->username, $user->email, $user->created_at?->format('Y-m-d H:i')) : '';
                        $process((int) $user->id, $label);
                    }
                });
        }

        $this->newLine();

        foreach ($counts as $outcome => $count) {
            if ($count > 0) {
                $this->info(sprintf('%s: %d', $outcome, $count));
            }
        }

        if (array_sum($counts) === 0) {
            $this->info('No unconfirmed accounts to remove.');
        }

        return $counts[UnconfirmedAccountPurgeOutcome::Failed->value] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function userIdFilter(): int|null|false
    {
        $raw = $this->option('user');

        if (($raw === null || $raw === '') && $this->input->hasParameterOption('--user')) {
            $this->error('The --user option must be a positive integer id.');

            return false;
        }

        if ($raw === null) {
            return null;
        }

        if (! ctype_digit((string) $raw) || (int) $raw < 1) {
            $this->error('The --user option must be a positive integer id.');

            return false;
        }

        return (int) $raw;
    }
}
