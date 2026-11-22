<?php

namespace App\Actions\Traffic;

use App\Models\AccountAttribution;
use App\Models\GameAttempt;
use App\Models\TrafficVisit;
use Illuminate\Support\Facades\DB;

/**
 * What Retention groups and filters an account by, copied onto the
 * attributions written without it (AccountAttribution::creditedKeys()): an
 * attribution credited to a visit with no device kept, or to an attempt with
 * no game, which only the release before the copies writes — during the
 * moments a deploy runs between migrating and switching over. Every
 * attribution written since carries them (RecordAccountSignupAction), so
 * this finds nothing on any other run. Each found is locked and read again
 * before it is written, so a pointer let go meanwhile is never copied from,
 * and the source and device of a visit let go stay as its purge left them.
 * For analytics:refresh-visits, a few hundred a run, found by one scan of
 * the attributions in any order — a few milliseconds when there are none.
 */
final class CopyCreditedKeysAction
{
    private const int BATCH = 500;

    /** How many attributions got their copies. */
    public function handle(): int
    {
        $ids = AccountAttribution::query()
            ->where(fn ($query) => $query
                ->where(fn ($visit) => $visit->whereNotNull('credited_visit_id')->whereNull('credited_device'))
                ->orWhere(fn ($attempt) => $attempt->whereNotNull('credited_attempt_id')->whereNull('credited_game_id')))
            ->limit(self::BATCH)
            ->pluck('id');

        foreach ($ids as $id) {
            DB::transaction(function () use ($id): void {
                $attribution = AccountAttribution::query()->whereKey($id)->lockForUpdate()->first();

                if ($attribution === null) {
                    return;
                }

                $visit = $attribution->credited_visit_id === null ? null : TrafficVisit::query()->find($attribution->credited_visit_id);
                $keys = AccountAttribution::creditedKeys($visit, $attribution->credited_attempt_id === null ? null : GameAttempt::query()->find($attribution->credited_attempt_id));

                // A visit purged leaves the source and device copied from it: with none, only the game is written.
                $attribution->forceFill($visit === null ? ['credited_game_id' => $keys['credited_game_id']] : $keys)->save();
            });
        }

        return $ids->count();
    }
}
