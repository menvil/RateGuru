<?php

namespace App\Support\Traffic\Reports;

/**
 * A level of the report tree as the screen draws it: its ten biggest groups,
 * then the others added up in one row, so the level still adds up to its
 * total.
 */
final class ReportTree
{
    public const int SHOWN = 10;

    /**
     * @template T of array{figures: ReportFigures}
     *
     * @param  list<T>  $groups  biggest first
     * @return array{shown: list<T>, rest: ReportFigures|null, restCount: int, total: ReportFigures}
     */
    public static function level(array $groups, int $shown = self::SHOWN): array
    {
        $total = array_reduce($groups, fn (ReportFigures $sum, array $group): ReportFigures => $sum->plus($group['figures']), new ReportFigures);
        $rest = array_slice($groups, $shown);

        return [
            'shown' => array_slice($groups, 0, $shown),
            'rest' => $rest === [] ? null : array_reduce($rest, fn (ReportFigures $sum, array $group): ReportFigures => $sum->plus($group['figures']), new ReportFigures),
            'restCount' => count($rest),
            'total' => $total,
        ];
    }
}
