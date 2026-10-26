<?php

namespace App\Support\Studio\Concerns;

use App\Enums\UserStatus;
use App\Models\User;

/**
 * How the vote builders (BuildStudioPostVotesData,
 * BuildStudioDiscussionVotesData) give prepared votes to Personas: the
 * voters drawn in an order a seed fixes, or the ones chosen by hand; and each
 * target's places given to them, a Persona keeping the vote it has saved as
 * far as the counts allow. Only reads.
 */
trait DrawsPersonaVotes
{
    /**
     * The Personas chosen by hand, but the one left out: who may take a new
     * vote — active Personas able to vote — in the order the seed draws them,
     * and who may only keep the votes it has, restricted since. One deleted,
     * missing or no longer a Persona is neither.
     *
     * @param  list<int>  $ids
     * @return array{0: list<int>, 1: list<int>}
     */
    private static function chosenVoters(array $ids, ?int $leftOut, int $seed): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn (int $id): bool => $id > 0 && $id !== $leftOut)));
        $people = User::query()->whereKey($ids)->get()->keyBy('id');
        $voters = [];
        $keepers = [];

        foreach ($ids as $id) {
            // The role and the status that count are the ones the row has now.
            $persona = $people->get($id);

            if ($persona === null || ! $persona->isPersona() || $persona->isTombstoned()) {
                continue;
            }

            if ($persona->status === UserStatus::Active && $persona->canVote()) {
                $voters[] = $id;
            } else {
                $keepers[] = $id;
            }
        }

        return [self::drawn($voters, $seed, 'voters'), $keepers];
    }

    /**
     * As many voters as asked for, drawn from the pool in the order the seed
     * fixes — but while the saved votes are preferred, the Personas who cast
     * them first, so a draw opened again, with a seed of its own, keeps them
     * as long as it asks for as many voters as they are, or more.
     *
     * @param  list<int>  $pool
     * @param  list<int>  $saved  the Personas with a saved vote
     * @return list<int>
     */
    private static function randomVoters(array $pool, array $saved, int $count, int $seed, bool $keep): array
    {
        $drawn = self::drawn($pool, $seed, 'voters');

        if ($keep) {
            $drawn = [...array_values(array_intersect($drawn, $saved)), ...array_values(array_diff($drawn, $saved))];
        }

        return array_slice($drawn, 0, max(0, min($count, count($drawn))));
    }

    /**
     * Gives each choice its count of Personas: first the saved choices kept —
     * always on a choice no longer open, which nobody new may take, and on the
     * others while the saved votes are preferred — then the open choices
     * still short, to the voters free on this target, those with a saved
     * choice on it first while preferred, so their vote changes rather than
     * another's being made.
     *
     * @param  list<int>  $voters  who may take a new choice, in the order drawn
     * @param  list<int>  $keepers  who may only keep the choice they have
     * @param  array<int, string>  $previous  the choice each Persona has saved on this target
     * @param  array<string, int>  $slots  how many each choice takes, in order
     * @param  array<string, bool>  $open  whether a choice takes a Persona new to it
     * @return array{0: array<int, string>, 1: int} the choice of each Persona, and how many places nobody took
     */
    private static function assign(array $voters, array $keepers, array $previous, array $slots, array $open, bool $keep): array
    {
        $assigned = [];
        $left = $slots;

        foreach (array_keys($slots) as $choice) {
            if ($keep || ! $open[$choice]) {
                foreach ([...$voters, ...$keepers] as $persona) {
                    if ($left[$choice] > 0 && ! isset($assigned[$persona]) && ($previous[$persona] ?? null) === $choice) {
                        $assigned[$persona] = $choice;
                        $left[$choice]--;
                    }
                }
            }
        }

        $free = array_values(array_filter($voters, fn (int $persona): bool => ! isset($assigned[$persona])));

        if ($keep) {
            $free = [
                ...array_filter($free, fn (int $persona): bool => isset($previous[$persona])),
                ...array_filter($free, fn (int $persona): bool => ! isset($previous[$persona])),
            ];
        }

        foreach (array_keys($slots) as $choice) {
            while ($open[$choice] && $left[$choice] > 0 && $free !== []) {
                $assigned[(int) array_shift($free)] = $choice;
                $left[$choice]--;
            }
        }

        return [$assigned, array_sum($left)];
    }

    /**
     * The order a target's votes come in: while the saved votes are
     * preferred, the Personas who had a vote on it first, in the order their
     * votes came, so votes asked for again come at the moments they had; then
     * the others, in the order the seed draws them.
     *
     * @param  list<int>  $personas
     * @param  array<int, int>  $previous  the moment each Persona's saved vote on this target came
     * @return list<int>
     */
    private static function timeOrder(array $personas, array $previous, bool $keep, int $seed, string $purpose): array
    {
        if (! $keep) {
            return self::drawn($personas, $seed, $purpose);
        }

        $kept = array_values(array_filter($personas, fn (int $persona): bool => isset($previous[$persona])));
        usort($kept, fn (int $a, int $b): int => [$previous[$a], $a] <=> [$previous[$b], $b]);

        return [...$kept, ...self::drawn(array_values(array_filter($personas, fn (int $persona): bool => ! isset($previous[$persona]))), $seed, $purpose)];
    }

    /**
     * The ids in the order a seed draws them for one purpose: the same seed
     * draws the same order, whatever order they came in.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private static function drawn(array $ids, int $seed, string $purpose): array
    {
        usort($ids, fn (int $a, int $b): int => [hash('xxh3', "{$seed}|{$purpose}|{$a}"), $a] <=> [hash('xxh3', "{$seed}|{$purpose}|{$b}"), $b]);

        return $ids;
    }
}
