<?php

namespace App\Support\Games\Traffic;

/**
 * Whether an audience's shares can be applied: whole percentages from 1 to
 * 100 for versions that may take traffic, adding up to exactly 100. No
 * shares at all is allowed too — an explicit "no traffic" for the audience.
 */
final class TrafficShareValidator
{
    /**
     * @param  array<int, mixed>  $shares  version id => share
     * @param  list<int>  $assignable  the versions that may take traffic
     * @return list<string>
     */
    public static function issues(array $shares, array $assignable): array
    {
        if ($shares === []) {
            return [];
        }

        $issues = [];
        $total = 0;

        foreach ($shares as $versionId => $share) {
            if (! in_array($versionId, $assignable, true)) {
                $issues[] = 'Only active versions of this game can have a share.';

                continue;
            }

            if (! is_int($share) || $share < 1 || $share > 100) {
                $issues[] = 'Each share is a whole number from 1 to 100.';

                continue;
            }

            $total += $share;
        }

        if ($issues === [] && $total !== 100) {
            $issues[] = "Shares add up to {$total}%. Make them 100% to apply.";
        }

        return array_values(array_unique($issues));
    }
}
