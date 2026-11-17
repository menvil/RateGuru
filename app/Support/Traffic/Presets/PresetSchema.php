<?php

namespace App\Support\Traffic\Presets;

use App\Enums\TrafficChannel;
use App\Enums\TrafficConnector;
use App\Enums\TrafficCostUnit;
use App\Support\Traffic\Links\LinkParams;
use App\Support\Traffic\Postbacks\PostbackTemplate;

/**
 * Whether config/traffic_presets.php is what a source can be made from:
 * every preset complete, its link parameters ones the click capture reads,
 * its postback address one RateGuru can send once filled in, its connector
 * described. A test runs it over the file.
 */
final class PresetSchema
{
    public const array ATTRIBUTION_DAYS = [1, 2, 3, 7, 30];

    private const array RESPONSE_OUTCOMES = ['ok', 'duplicate', 'rejected'];

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $reservedParams  parameters the site's own pages read
     * @return list<string>
     */
    public static function violations(array $config, array $reservedParams): array
    {
        $problems = [];
        $presets = is_array($config['presets'] ?? null) ? $config['presets'] : [];
        $connectors = is_array($config['connectors'] ?? null) ? $config['connectors'] : [];
        $keys = [];

        foreach ($connectors as $key => $connector) {
            if (TrafficConnector::tryFrom((string) $key) === null) {
                $problems[] = "connector {$key}: no such connector in code";
            }

            foreach (['name', 'credential_label', 'credential_help', 'promise'] as $field) {
                if (! is_string($connector[$field] ?? null) || $connector[$field] === '') {
                    $problems[] = "connector {$key}: {$field} missing";
                }
            }

            if (! is_bool($connector['verified'] ?? null)) {
                $problems[] = "connector {$key}: verified must be true or false";
            }
        }

        foreach ($presets as $key => $preset) {
            $at = "preset {$key}";
            $custom = ($preset['custom'] ?? null) === true;
            $linkKey = (string) ($preset['link_key'] ?? '');

            foreach (['name', 'fill'] as $field) {
                if (! is_string($preset[$field] ?? null) || $preset[$field] === '') {
                    $problems[] = "{$at}: {$field} missing";
                }
            }

            if (! $custom && (preg_match('/^[a-z0-9_-]{2,40}$/', $linkKey) !== 1 || TrafficChannel::tryFrom($linkKey) !== null)) {
                $problems[] = "{$at}: link_key must be 2–40 of a-z, 0-9, _ and -, and not a channel's name";
            }

            if ($linkKey !== '' && in_array($linkKey, $keys, true)) {
                $problems[] = "{$at}: link_key {$linkKey} is another preset's";
            }

            $keys[] = $linkKey;

            if (! in_array($preset['attribution_days'] ?? null, self::ATTRIBUTION_DAYS, true)) {
                $problems[] = "{$at}: attribution_days must be one of 1, 2, 3, 7 or 30";
            }

            if (! in_array((string) ($preset['reporting_timezone'] ?? ''), timezone_identifiers_list(), true)) {
                $problems[] = "{$at}: reporting_timezone is not a time zone";
            }

            if (TrafficCostUnit::tryFrom((string) ($preset['cost_unit'] ?? '')) === null) {
                $problems[] = "{$at}: cost_unit must be click or cpm";
            }

            $connector = $preset['connector'] ?? null;

            if ($connector !== null && ! array_key_exists((string) $connector, $connectors)) {
                $problems[] = "{$at}: connector {$connector} is not described under connectors";
            }

            $problems = [...$problems, ...self::linkParamViolations($at, is_array($preset['link_params'] ?? null) ? $preset['link_params'] : [], $reservedParams)];
            $problems = [...$problems, ...self::postbackViolations($at, is_array($preset['postback'] ?? null) ? $preset['postback'] : [])];
        }

        return $problems;
    }

    /**
     * @param  array<int, mixed>  $params
     * @param  list<string>  $reserved
     * @return list<string>
     */
    private static function linkParamViolations(string $at, array $params, array $reserved): array
    {
        return array_map(
            fn (array $problem): string => $at.': '.($problem['index'] >= 0 ? 'link parameter '.($problem['index'] + 1).': ' : '').$problem['message'],
            LinkParams::violations($params, $reserved),
        );
    }

    /**
     * @param  array<string, mixed>  $postback
     * @return list<string>
     */
    private static function postbackViolations(string $at, array $postback): array
    {
        $problems = [];
        $template = (string) ($postback['url_template'] ?? '');

        if ($template !== '') {
            foreach (PostbackTemplate::violations($template, allowGaps: true) as $problem) {
                $problems[] = "{$at}: {$problem}";
            }
        }

        foreach (is_array($postback['response_rules'] ?? null) ? $postback['response_rules'] : [] as $rule) {
            if (! in_array($rule['outcome'] ?? null, self::RESPONSE_OUTCOMES, true) || ! in_array($rule['match'] ?? null, ['equals', 'contains'], true) || ! is_string($rule['text'] ?? null) || $rule['text'] === '') {
                $problems[] = "{$at}: a response rule needs an outcome (ok, duplicate, rejected), a match (equals, contains) and a text";
            }
        }

        if (! in_array($postback['otherwise'] ?? null, ['ok', 'retry'], true)) {
            $problems[] = "{$at}: postback.otherwise must be ok or retry";
        }

        return $problems;
    }
}
