<?php

namespace App\Support\Traffic\Worth;

/**
 * Works out, from the matured visits of new visitors, the chance that one
 * who reached a step ends up a confirmed account — for every slice of game,
 * source (or channel), device and country group, each shrunk towards the
 * slice above it so a slice with few accounts says little on its own:
 * p = (x + 30) / (n + 30 / p0), x the accounts and n the visitors who
 * reached the step, p0 the slice above's chance; the whole site is x / n.
 * How often accounts post and come back is shrunk the same way:
 * r = (x + 30·r0) / (n + 30).
 */
final class ChanceCalculator
{
    public const int STANDS_FROM_ACCOUNTS = 30;

    private const int PRIOR = 30;

    /** The milestone steps the sample counts, beside opened and the rounds. */
    public const array MILESTONES = ['started', 'completed', 'email', 'shared', 'replayed', 'next_game', 'page', 'feed', 'post_opened', 'post_voted', 'post_rated', 'feed_voted', 'signup'];

    /**
     * @param  list<array<string, mixed>>  $cells  sample rows: game_id, source_id, channel, device, country, rounds_answered, visits, accounts, {step}_n and {step}_x for each milestone, post_n, post_x, returned_n, returned_x
     * @param  list<array{key: string, name: string, percent: int, countries: list<string>}>  $groups
     * @return list<array{slice: Slice, step: string, visitors: int, accounts: int, chance: float, slice_accounts: int, thin: bool, stands_on: string}>
     */
    public static function calculate(array $cells, array $groups): array
    {
        /** @var array<string, array{slice: Slice, steps: array<string, array{0: int, 1: int}>, rounds: array<int, array{0: int, 1: int}>}> $slices */
        $slices = [];

        foreach ($cells as $cell) {
            $own = new Slice(
                isset($cell['game_id']) ? (int) $cell['game_id'] : null,
                isset($cell['source_id']) ? (int) $cell['source_id'] : null,
                isset($cell['source_id']) ? null : (isset($cell['channel']) ? (string) $cell['channel'] : null),
                isset($cell['device']) ? (string) $cell['device'] : null,
                CountryGroups::of($groups, isset($cell['country']) ? (string) $cell['country'] : null)['key'],
            );
            $answered = (int) ($cell['rounds_answered'] ?? 0);

            foreach ($own->subsets() as $slice) {
                $key = $slice->key();
                $slices[$key] ??= ['slice' => $slice, 'steps' => [], 'rounds' => []];
                self::add($slices[$key]['steps'], 'opened', (int) $cell['visits'], (int) $cell['accounts']);

                foreach (self::MILESTONES as $step) {
                    self::add($slices[$key]['steps'], $step, (int) ($cell[$step.'_n'] ?? 0), (int) ($cell[$step.'_x'] ?? 0));
                }

                self::add($slices[$key]['steps'], 'post_bonus', (int) ($cell['post_n'] ?? 0), (int) ($cell['post_x'] ?? 0));
                self::add($slices[$key]['steps'], 'returned_bonus', (int) ($cell['returned_n'] ?? 0), (int) ($cell['returned_x'] ?? 0));

                if ($answered > 0) {
                    $round = $slices[$key]['rounds'][$answered] ?? [0, 0];
                    $slices[$key]['rounds'][$answered] = [$round[0] + (int) $cell['visits'], $round[1] + (int) $cell['accounts']];
                }
            }
        }

        foreach ($slices as $key => $slice) {
            // round_n: the visits that answered n rounds or more.
            krsort($slice['rounds']);
            $n = 0;
            $x = 0;

            foreach ($slice['rounds'] as $answered => [$visits, $accounts]) {
                $n += $visits;
                $x += $accounts;
                $slices[$key]['steps']['round_'.$answered] = [$n, $x];
            }

            // A round below the deepest with no visit of its own still has every deeper visit.
            if ($slice['rounds'] !== []) {
                $deepest = max(array_keys($slice['rounds']));

                for ($round = $deepest - 1; $round >= 1; $round--) {
                    $slices[$key]['steps']['round_'.$round] ??= $slices[$key]['steps']['round_'.($round + 1)];
                }
            }
        }

        // From the whole site down, so every slice's parent is worked out first.
        uasort($slices, fn (array $a, array $b): int => count($a['slice']->chain()) <=> count($b['slice']->chain()));
        $chances = [];
        $rows = [];

        foreach ($slices as $key => $slice) {
            $sliceAccounts = $slice['steps']['opened'][1] ?? 0;
            $standsOn = self::standsOn($slice['slice'], $slices);

            foreach ($slice['steps'] as $step => [$n, $x]) {
                if ($n <= 0) {
                    continue;
                }

                $parent = $slice['slice']->parent();
                $p0 = $parent === null ? null : self::parentChance($parent, $step, $chances);
                $bonus = in_array($step, ['post_bonus', 'returned_bonus'], true);
                $chance = match (true) {
                    $p0 === null => $x / $n,
                    $bonus => ($x + self::PRIOR * $p0) / ($n + self::PRIOR),
                    $p0 <= 0.0 => $x / $n,
                    default => ($x + self::PRIOR) / ($n + self::PRIOR / $p0),
                };
                $chance = max(0.0, min(1.0, $chance));
                $chances[$key][$step] = $chance;
                $rows[] = [
                    'slice' => $slice['slice'],
                    'step' => $step,
                    'visitors' => $n,
                    'accounts' => $x,
                    'chance' => $chance,
                    'slice_accounts' => $sliceAccounts,
                    'thin' => $sliceAccounts < self::STANDS_FROM_ACCOUNTS,
                    'stands_on' => $standsOn,
                ];
            }
        }

        return $rows;
    }

    /** @param  array<string, array{0: int, 1: int}>  $steps */
    private static function add(array &$steps, string $step, int $n, int $x): void
    {
        $current = $steps[$step] ?? [0, 0];
        $steps[$step] = [$current[0] + $n, $current[1] + $x];
    }

    /** @param  array<string, array<string, float>>  $chances */
    private static function parentChance(Slice $parent, string $step, array $chances): float
    {
        foreach ($parent->chain() as $slice) {
            if (isset($chances[$slice->key()][$step])) {
                return $chances[$slice->key()][$step];
            }
        }

        return 0.0;
    }

    /**
     * The nearest slice, itself included, with enough accounts to stand on
     * its own; the whole site when none has.
     *
     * @param  array<string, array{slice: Slice, steps: array<string, array{0: int, 1: int}>, rounds: array<int, array{0: int, 1: int}>}>  $slices
     */
    private static function standsOn(Slice $slice, array $slices): string
    {
        foreach ($slice->chain() as $candidate) {
            if (($slices[$candidate->key()]['steps']['opened'][1] ?? 0) >= self::STANDS_FROM_ACCOUNTS) {
                return $candidate->key();
            }
        }

        return '*';
    }
}
