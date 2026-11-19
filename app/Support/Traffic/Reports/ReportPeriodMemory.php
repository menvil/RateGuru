<?php

namespace App\Support\Traffic\Reports;

use Illuminate\Http\Request;

/**
 * The period an administrator last chose in a report page's period picker —
 * a preset or a range, as the page read it and its address names it — kept
 * in their session, so the page opens on it again when its address names no
 * period. Each picker keeps its own, by name, and each administrator their
 * own. An address that names a period always wins, and is kept in turn.
 * Whether what is kept can still be read is the page's to say, as for any
 * address: a range now older than the page keeps, or a preset it no longer
 * offers, is its default.
 */
final class ReportPeriodMemory
{
    /** What an address names a period by: a preset, or a range's two days. */
    public const array PARAMETERS = ['period', 'from', 'to'];

    /** Where in the session every picker's period is kept, by administrator and picker. */
    public const string SESSION_KEY = 'report-periods';

    /**
     * The period a page opens on when its address names none: the one last
     * chosen in its picker, as the address would name it. None while the
     * address names a period of its own, or nothing is kept.
     *
     * @return array{period: string, from: string, to: string}|null
     */
    public static function recall(Request $request, string $picker): ?array
    {
        $key = self::key($picker);

        if ($key === null || self::named($request)) {
            return null;
        }

        $kept = session()->get($key);

        if (! is_array($kept) || ! is_string($kept['period'] ?? null) || ! is_string($kept['from'] ?? null) || ! is_string($kept['to'] ?? null)) {
            return null;
        }

        return ['period' => $kept['period'], 'from' => $kept['from'], 'to' => $kept['to']];
    }

    /** Keeps the period a picker now shows, as the page read it: a preset with no days, or a range's days. */
    public static function remember(string $picker, string $period, string $from, string $to): void
    {
        $key = self::key($picker);

        if ($key !== null) {
            session()->put($key, ['period' => $period, 'from' => $from, 'to' => $to]);
        }
    }

    /** Whether the address names a period of its own, read or not. */
    public static function named(Request $request): bool
    {
        foreach (self::PARAMETERS as $parameter) {
            if ($request->query->has($parameter)) {
                return true;
            }
        }

        return false;
    }

    /** Where the administrator's period of a picker is kept; nowhere for nobody signed in. */
    private static function key(string $picker): ?string
    {
        $user = auth()->id();

        return $user === null ? null : self::SESSION_KEY.'.'.$user.'.'.$picker;
    }
}
