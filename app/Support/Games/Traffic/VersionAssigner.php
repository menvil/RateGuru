<?php

namespace App\Support\Games\Traffic;

/**
 * Picks a version for a visitor from the shares in effect, the same one every
 * time for the same seed: the seed names the game, the period and the visitor,
 * so a page drawn before anything is stored and the assignment stored later
 * agree.
 *
 * Shares of versions that cannot be played are left out by the caller; the
 * rest then divide the visitors between them in proportion.
 */
final class VersionAssigner
{
    /**
     * @param  array<int, int>  $shares  version id => share, in version order
     */
    public static function choose(array $shares, string $seed): ?int
    {
        $shares = array_filter($shares, fn (int $share): bool => $share > 0);
        $total = array_sum($shares);

        if ($total === 0) {
            return null;
        }

        $bucket = self::bucket($seed, $total);
        $cumulative = 0;

        foreach ($shares as $versionId => $share) {
            $cumulative += $share;

            if ($bucket < $cumulative) {
                return $versionId;
            }
        }

        return array_key_last($shares);
    }

    /** A number from 0 to $total − 1, spread evenly over seeds. */
    public static function bucket(string $seed, int $total): int
    {
        return (int) (hexdec(substr(hash('sha256', $seed), 0, 12)) % $total);
    }
}
