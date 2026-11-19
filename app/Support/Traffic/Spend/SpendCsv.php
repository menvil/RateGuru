<?php

namespace App\Support\Traffic\Spend;

use App\Support\Traffic\Money\TrafficMoney;

/**
 * A spend file as RateGuru takes one: UTF-8, comma or semicolon, a header
 * row naming `day` (the network's day, YYYY-MM-DD) and `amount` (US dollars,
 * a point for decimals), and if wanted `campaign`, `site`, `zone`, `clicks`
 * and `impressions`; up to 20 000 rows. Rows with the same day, campaign,
 * site and zone are added up. One mistake refuses the whole file, naming
 * the row.
 */
final class SpendCsv
{
    public const int MOST_ROWS = 20000;

    private const array COLUMNS = ['day', 'amount', 'campaign', 'site', 'zone', 'clicks', 'impressions'];

    /**
     * @return array{rows: list<array{day: string, campaign: string, site: string, zone: string, amount: string, clicks: int|null, impressions: int|null}>, errors: list<string>}
     */
    public static function parse(string $contents, string $today): array
    {
        $contents = (string) preg_replace('/^\xEF\xBB\xBF/', '', $contents);

        if (! mb_check_encoding($contents, 'UTF-8')) {
            return ['rows' => [], 'errors' => ['The file isn’t UTF-8 text. Save it as CSV UTF-8 and try again.']];
        }

        $lines = array_values(array_filter(preg_split('/\r\n|\n|\r/', $contents) ?: [], fn (string $line): bool => trim($line) !== ''));

        if ($lines === []) {
            return ['rows' => [], 'errors' => ['The file is empty.']];
        }

        $separator = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',';
        $header = array_map(fn (?string $name): string => strtolower(trim((string) $name)), str_getcsv($lines[0], $separator, '"', ''));

        foreach (['day', 'amount'] as $required) {
            if (! in_array($required, $header, true)) {
                return ['rows' => [], 'errors' => ["The header has no “{$required}” column. The first row names the columns: day, amount, and if wanted campaign, site, zone, clicks, impressions."]];
            }
        }

        $unknown = array_diff(array_filter($header), self::COLUMNS);

        if ($unknown !== []) {
            return ['rows' => [], 'errors' => ['Unknown columns: '.implode(', ', $unknown).'. Use day, amount, campaign, site, zone, clicks and impressions.']];
        }

        if (count($lines) - 1 > self::MOST_ROWS) {
            return ['rows' => [], 'errors' => ['The file has more than '.number_format(self::MOST_ROWS).' rows. Split it by month.']];
        }

        $rows = [];
        $errors = [];

        foreach (array_slice($lines, 1) as $index => $line) {
            $row = $index + 2;
            $values = str_getcsv($line, $separator, '"', '');

            if (count($values) > count($header)) {
                $errors[] = "Row {$row} has more cells than the header names. Write amounts with a point, such as 12.50, not a comma.";

                continue;
            }

            $cells = array_combine($header, array_pad($values, count($header), ''));
            $day = trim((string) $cells['day']);
            $amount = trim((string) $cells['amount']);

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1 || ! checkdate((int) substr($day, 5, 2), (int) substr($day, 8, 2), (int) substr($day, 0, 4))) {
                $errors[] = "Row {$row}: “{$day}” isn’t a day written YYYY-MM-DD.";

                continue;
            }

            if ($day > $today) {
                $errors[] = "Row {$row}: {$day} hasn’t come yet.";

                continue;
            }

            if (preg_match('/^\d{1,10}(\.\d{1,8})?$/', $amount) !== 1) {
                $errors[] = "Row {$row}: “{$amount}” isn’t an amount in dollars, such as 12.50.";

                continue;
            }

            $count = fn (string $column): ?int => ($cells[$column] ?? '') === '' ? null : (ctype_digit(trim((string) $cells[$column])) ? (int) $cells[$column] : -1);
            $clicks = $count('clicks');
            $impressions = $count('impressions');

            if ($clicks === -1 || $impressions === -1) {
                $errors[] = "Row {$row}: clicks and impressions are whole numbers.";

                continue;
            }

            $key = implode('|', [$day, trim((string) ($cells['campaign'] ?? '')), trim((string) ($cells['site'] ?? '')), trim((string) ($cells['zone'] ?? ''))]);
            $previous = $rows[$key] ?? null;
            $rows[$key] = [
                'day' => $day,
                'campaign' => mb_substr(trim((string) ($cells['campaign'] ?? '')), 0, 120),
                'site' => mb_substr(trim((string) ($cells['site'] ?? '')), 0, 120),
                'zone' => mb_substr(trim((string) ($cells['zone'] ?? '')), 0, 120),
                'amount' => TrafficMoney::stored(TrafficMoney::of($amount)->plus(TrafficMoney::of($previous['amount'] ?? 0))),
                'clicks' => $clicks === null && ($previous['clicks'] ?? null) === null ? null : ($clicks ?? 0) + (int) ($previous['clicks'] ?? 0),
                'impressions' => $impressions === null && ($previous['impressions'] ?? null) === null ? null : ($impressions ?? 0) + (int) ($previous['impressions'] ?? 0),
            ];
        }

        return $errors === [] ? ['rows' => array_values($rows), 'errors' => []] : ['rows' => [], 'errors' => array_slice($errors, 0, 20)];
    }
}
