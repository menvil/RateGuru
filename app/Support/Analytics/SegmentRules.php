<?php

namespace App\Support\Analytics;

use App\Enums\SegmentOperator;
use App\Enums\SegmentParameter;
use App\Enums\SegmentVerdict;
use App\Enums\TrafficDevice;

/**
 * The conditions of a paid source's segment, every one of which a click must
 * meet: a parameter it carries — site, zone, campaign, creative, device,
 * country — that is any of the values given, or none of them, a click
 * without the parameter at all among those. Read from the drawer's draft,
 * with what is wrong with each condition said by its place; kept as stored
 * and read back; written as the SQL a visit matches, every value bound and
 * every column named by SegmentParameter; and said on a segment's row as its
 * chips. A campaign is typed by its id in the network and kept as the id of
 * the source's own campaign — the caller tells which of those the source
 * has. Also the verdict a segment gets against its whole source. Pure.
 *
 * @phpstan-type SegmentRule array{parameter: SegmentParameter, operator: SegmentOperator, values: list<int|string>}
 * @phpstan-type SegmentCondition array{parameter: string, operator: string, values: string}
 */
final class SegmentRules
{
    /** The most conditions a segment holds. */
    public const int MOST_CONDITIONS = 8;

    /** The most values one condition holds. */
    public const int MOST_VALUES = 25;

    /** The longest value, as long as the visit columns it is compared with. */
    public const int MAX_LENGTH = 120;

    /** A segment whose accounts per new visitor are at least this share of its source's, in percent, is Strong. */
    public const int STRONG_PERCENT = 125;

    /** A segment whose accounts per new visitor are at most this share of its source's, in percent, is a Block candidate. */
    public const int BLOCK_PERCENT = 50;

    /** How many values a chip names before saying how many more. */
    private const int NAMED_VALUES = 3;

    /**
     * The values typed into one condition's field: split at commas and line
     * breaks, without control characters, trimmed, each once.
     *
     * @return list<string>
     */
    public static function values(string $text): array
    {
        $parts = preg_split('/[,\r\n\t]+/u', $text);

        if ($parts === false) {
            return [];
        }

        $values = [];

        foreach ($parts as $part) {
            $value = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $part));

            if ($value !== '' && ! in_array($value, $values, true)) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * The ids in the network the campaign conditions of a draft name, each
     * once: what the caller looks up among the source's campaigns.
     *
     * @param  array<int|string, mixed>  $draft
     * @return list<string>
     */
    public static function campaignRefs(array $draft): array
    {
        $refs = [];

        foreach ($draft as $condition) {
            if (is_array($condition) && ($condition['parameter'] ?? null) === SegmentParameter::Campaign->value && is_string($condition['values'] ?? null)) {
                array_push($refs, ...self::values($condition['values']));
            }
        }

        return array_values(array_unique($refs));
    }

    /**
     * A draft's conditions read into rules, in their order, with what is
     * wrong with each that can't be read, by its place in the draft: a
     * parameter or operator that isn't one, no value, too many or too long,
     * a device or country there is no such, or a campaign the source hasn't.
     * A condition that can't be read is left out of the rules.
     *
     * @param  array<int|string, mixed>  $draft
     * @param  list<string>  $countries  every country code there is
     * @param  array<string, int>  $campaigns  the source's campaigns among those the draft names, by their ids in the network
     * @return array{rules: list<SegmentRule>, errors: array<int, string>}
     */
    public static function read(array $draft, array $countries, array $campaigns, string $sourceName): array
    {
        $rules = [];
        $errors = [];

        foreach (array_values($draft) as $index => $condition) {
            $condition = is_array($condition) ? $condition : [];
            $parameter = is_string($condition['parameter'] ?? null) ? SegmentParameter::tryFrom($condition['parameter']) : null;
            $operator = is_string($condition['operator'] ?? null) ? SegmentOperator::tryFrom($condition['operator']) : null;

            if ($parameter === null) {
                $errors[$index] = 'Choose what this condition reads.';

                continue;
            }

            if ($operator === null) {
                $errors[$index] = 'Choose “is any of” or “is not”.';

                continue;
            }

            $typed = self::values(is_string($condition['values'] ?? null) ? $condition['values'] : '');
            $values = self::checked($parameter, $typed, $countries, $campaigns, $sourceName);

            if (is_string($values)) {
                $errors[$index] = $values;

                continue;
            }

            $rules[] = ['parameter' => $parameter, 'operator' => $operator, 'values' => $values];
        }

        return ['rules' => $rules, 'errors' => $errors];
    }

    /**
     * Rules as a segment stores them, read back: anything that isn't a rule
     * of a known parameter and operator with at least one value is left out.
     *
     * @return list<SegmentRule>
     */
    public static function stored(mixed $stored): array
    {
        if (! is_array($stored)) {
            return [];
        }

        $rules = [];

        foreach ($stored as $rule) {
            $parameter = is_array($rule) && is_string($rule['parameter'] ?? null) ? SegmentParameter::tryFrom($rule['parameter']) : null;
            $operator = is_array($rule) && is_string($rule['operator'] ?? null) ? SegmentOperator::tryFrom($rule['operator']) : null;
            $values = [];

            foreach (is_array($rule) && is_array($rule['values'] ?? null) ? $rule['values'] : [] as $value) {
                $read = match (true) {
                    $parameter === SegmentParameter::Campaign => is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null,
                    default => is_string($value) && $value !== '' ? $value : null,
                };

                if ($read !== null && ! in_array($read, $values, true)) {
                    $values[] = $read;
                }
            }

            if ($parameter !== null && $operator !== null && $values !== []) {
                $rules[] = ['parameter' => $parameter, 'operator' => $operator, 'values' => $values];
            }
        }

        return $rules;
    }

    /**
     * Rules as a segment stores them.
     *
     * @param  list<SegmentRule>  $rules
     * @return list<array{parameter: string, operator: string, values: list<int|string>}>
     */
    public static function store(array $rules): array
    {
        return array_map(fn (array $rule): array => ['parameter' => $rule['parameter']->value, 'operator' => $rule['operator']->value, 'values' => $rule['values']], $rules);
    }

    /**
     * The SQL a visit matches, with its bindings: every condition holds — the
     * parameter's column one of the values, or empty or none of them. Never
     * unknown, a column empty included, so the visits of no segment are
     * those for which every segment's SQL is false. Rules of no condition
     * match nothing.
     *
     * @param  list<SegmentRule>  $rules
     * @return array{0: string, 1: list<int|string>}
     */
    public static function predicate(array $rules): array
    {
        $parts = [];
        $bindings = [];

        foreach ($rules as $rule) {
            $column = $rule['parameter']->column();
            $in = $rule['operator'] === SegmentOperator::In;

            if ($rule['values'] === []) {
                $parts[] = $in ? '1 = 0' : '1 = 1';

                continue;
            }

            $marks = implode(', ', array_fill(0, count($rule['values']), '?'));
            $parts[] = $in ? "({$column} IS NOT NULL AND {$column} IN ({$marks}))" : "({$column} IS NULL OR {$column} NOT IN ({$marks}))";
            array_push($bindings, ...$rule['values']);
        }

        return $parts === [] ? ['1 = 0', []] : ['('.implode(' AND ', $parts).')', $bindings];
    }

    /**
     * How each condition reads on a segment's row: “Site is 1182”, “Country
     * is any of DE, AT”, “Device is not Desktop” — a campaign by its id in
     * the network, the first few values named and the rest counted.
     *
     * @param  list<SegmentRule>  $rules
     * @param  array<int, string>  $campaignRefs  the campaigns' ids in the network, by campaign id
     * @return list<string>
     */
    public static function chips(array $rules, array $campaignRefs = []): array
    {
        return array_map(function (array $rule) use ($campaignRefs): string {
            $values = self::shown($rule, $campaignRefs);
            $named = array_slice($values, 0, self::NAMED_VALUES);
            $more = count($values) - count($named);
            $verb = match (true) {
                $rule['operator'] === SegmentOperator::Out => 'is not',
                count($values) > 1 => 'is any of',
                default => 'is',
            };

            return $rule['parameter']->label().' '.$verb.' '.implode(', ', $named).($more > 0 ? ' and '.$more.' more' : '');
        }, $rules);
    }

    /**
     * Rules back as the drawer's draft holds them: each value as it is typed
     * — a campaign by its id in the network.
     *
     * @param  list<SegmentRule>  $rules
     * @param  array<int, string>  $campaignRefs  the campaigns' ids in the network, by campaign id
     * @return list<SegmentCondition>
     */
    public static function draft(array $rules, array $campaignRefs = []): array
    {
        return array_map(fn (array $rule): array => [
            'parameter' => $rule['parameter']->value,
            'operator' => $rule['operator']->value,
            'values' => implode(', ', $rule['parameter'] === SegmentParameter::Campaign
                ? array_map(fn (int|string $id): string => $campaignRefs[(int) $id] ?? (string) $id, $rule['values'])
                : array_map(strval(...), $rule['values'])),
        ], $rules);
    }

    /**
     * The campaigns rules name, by campaign id, each once.
     *
     * @param  list<SegmentRule>  $rules
     * @return list<int>
     */
    public static function campaignIds(array $rules): array
    {
        $ids = [];

        foreach ($rules as $rule) {
            if ($rule['parameter'] === SegmentParameter::Campaign) {
                array_push($ids, ...array_map(intval(...), $rule['values']));
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * A segment's verdict against its whole source, by accounts per new
     * visitor: too little data while the segment has fewer new visitors than
     * a rate of it needs, or the source fewer accounts than a verdict needs;
     * then Strong at the strong share of the source's rate or more, a Block
     * candidate at the block share or less, Average between. Worked out in
     * whole numbers, so a rate exactly at a share is never misjudged.
     */
    public static function verdict(VisitorFigures $segment, VisitorFigures $source, int $leastVisitors, int $leastSourceAccounts): SegmentVerdict
    {
        if ($segment->accountRate($leastVisitors) === null || $source->accounts < max(1, $leastSourceAccounts) || $source->newVisitors === 0) {
            return SegmentVerdict::TooLittle;
        }

        $segmentShare = $segment->accounts * $source->newVisitors * 100;
        $sourceShare = $source->accounts * $segment->newVisitors;

        return match (true) {
            $segmentShare >= self::STRONG_PERCENT * $sourceShare => SegmentVerdict::Strong,
            $segmentShare <= self::BLOCK_PERCENT * $sourceShare => SegmentVerdict::Block,
            default => SegmentVerdict::Average,
        };
    }

    /**
     * The values of one condition checked for its parameter: a device and a
     * country as stored, a campaign as the source's campaign's id — or what
     * is wrong with them.
     *
     * @param  list<string>  $typed
     * @param  list<string>  $countries
     * @param  array<string, int>  $campaigns
     * @return list<int|string>|string
     */
    private static function checked(SegmentParameter $parameter, array $typed, array $countries, array $campaigns, string $sourceName): array|string
    {
        if ($typed === []) {
            return $parameter === SegmentParameter::Device ? 'Pick at least one device.' : 'Type at least one value.';
        }

        if (count($typed) > self::MOST_VALUES) {
            return 'Up to '.self::MOST_VALUES.' values in one condition.';
        }

        foreach ($typed as $value) {
            if (mb_strlen($value) > self::MAX_LENGTH) {
                return 'Each value is up to '.self::MAX_LENGTH.' characters.';
            }
        }

        if ($parameter === SegmentParameter::Device) {
            $devices = array_map(strtolower(...), $typed);

            return array_filter($devices, fn (string $value): bool => TrafficDevice::tryFrom($value) === null) !== []
                ? 'Pick Mobile, Desktop or both.'
                : array_values(array_unique($devices));
        }

        if ($parameter === SegmentParameter::Country) {
            $codes = array_map(strtoupper(...), $typed);
            $wrong = array_values(array_filter($codes, fn (string $code): bool => ! in_array($code, $countries, true)));

            return $wrong !== [] ? 'No country has the code '.$wrong[0].'.' : array_values(array_unique($codes));
        }

        if ($parameter === SegmentParameter::Campaign) {
            $wrong = array_values(array_filter($typed, fn (string $ref): bool => ! array_key_exists($ref, $campaigns)));

            return $wrong !== []
                ? $sourceName.' has no '.(count($wrong) === 1 ? 'campaign ' : 'campaigns ').implode(', ', $wrong).'.'
                : array_values(array_unique(array_map(fn (string $ref): int => $campaigns[$ref], $typed)));
        }

        return $typed;
    }

    /**
     * A rule's values as a chip names them: a device by its label, a campaign
     * by its id in the network.
     *
     * @param  SegmentRule  $rule
     * @param  array<int, string>  $campaignRefs
     * @return list<string>
     */
    private static function shown(array $rule, array $campaignRefs): array
    {
        return array_map(fn (int|string $value): string => match ($rule['parameter']) {
            SegmentParameter::Device => TrafficDevice::tryFrom((string) $value)?->label() ?? (string) $value,
            SegmentParameter::Campaign => $campaignRefs[(int) $value] ?? '#'.$value,
            default => (string) $value,
        }, $rule['values']);
    }
}
