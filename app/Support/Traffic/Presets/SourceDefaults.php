<?php

namespace App\Support\Traffic\Presets;

use App\Enums\TrafficBlockLevel;
use App\Enums\TrafficConnector;
use App\Enums\TrafficCostUnit;
use App\Enums\TrafficEvent;
use App\Enums\TrafficFixedPeriod;
use App\Enums\TrafficLinkParam;
use App\Enums\TrafficSourceStatus;
use App\Enums\TrafficSourceType;
use App\Enums\TrafficSpendMode;

/**
 * What a new source starts with: a copy of its network's preset — link
 * tokens, response rules, time zone, window, cost unit and connector — and
 * the preset's postback address as a first rule for the goal, kept off
 * until its gaps are filled in; or, for a banner or link bought at a fixed
 * price, a monthly price and nothing else. Spend comes from the API where a
 * connector reads it, else from the click's cost when the link carries one,
 * else from a rate; blocking is by zone where the link names zones and the
 * connector blocks them, else by site.
 */
final class SourceDefaults
{
    /** Connectors whose API blocks zones; the others block sites. */
    private const array ZONE_BLOCKERS = [TrafficConnector::ExoClick];

    /**
     * @param  array<string, mixed>  $preset
     * @param  list<string>  $takenLinkKeys
     * @return array{source: array<string, mixed>, rules: list<array{id: null, event: string, goal_code: null, needs_event: string, url_template: string, enabled: bool}>}
     */
    public static function forPreset(string $key, array $preset, bool $connectorDescribed, array $takenLinkKeys): array
    {
        /** @var list<array{kind: string, label: string, param: string, token: string, note: string}> $params */
        $params = is_array($preset['link_params'] ?? null) ? array_values($preset['link_params']) : [];
        $kinds = array_column($params, 'token', 'kind');
        $connector = $connectorDescribed ? TrafficConnector::tryFrom((string) ($preset['connector'] ?? '')) : null;
        $postback = is_array($preset['postback'] ?? null) ? $preset['postback'] : [];
        $responseRules = is_array($postback['response_rules'] ?? null) ? array_values($postback['response_rules']) : [];
        $otherwise = (string) ($postback['otherwise'] ?? 'ok');
        $template = (string) ($postback['url_template'] ?? '');
        $costUnit = TrafficCostUnit::tryFrom((string) ($preset['cost_unit'] ?? '')) ?? TrafficCostUnit::Click;
        $custom = ($preset['custom'] ?? false) === true;

        return [
            'source' => [
                'type' => TrafficSourceType::Network->value,
                'name' => $custom ? '' : (string) ($preset['name'] ?? ''),
                'link_key' => $custom ? '' : self::freeKey((string) ($preset['link_key'] ?? ''), $takenLinkKeys),
                'preset_key' => $key,
                'connector' => $connector?->value,
                'status' => TrafficSourceStatus::Active->value,
                'attribution_days' => (int) ($preset['attribution_days'] ?? 7),
                'click_id_ttl_hours' => isset($preset['click_id_ttl_hours']) ? (int) $preset['click_id_ttl_hours'] : null,
                'target_cpa' => null,
                'reporting_timezone' => (string) ($preset['reporting_timezone'] ?? 'UTC'),
                'link_params' => $params,
                'response_rules' => $responseRules === [] && $otherwise === 'ok' ? null : ['rules' => $responseRules, 'otherwise' => $otherwise],
                'spend_mode' => match (true) {
                    $connector !== null => TrafficSpendMode::Api->value,
                    ($kinds[TrafficLinkParam::Cost->value] ?? '') !== '' => TrafficSpendMode::ClickCost->value,
                    default => TrafficSpendMode::Rate->value,
                },
                'spend_estimate' => null,
                'cost_unit' => $costUnit->value,
                'rate_unit' => $costUnit->value,
                'rate_mobile' => null,
                'rate_desktop' => null,
                'block_level' => array_key_exists(TrafficLinkParam::Zone->value, $kinds) && in_array($connector, self::ZONE_BLOCKERS, true)
                    ? TrafficBlockLevel::Zone->value
                    : TrafficBlockLevel::Site->value,
                'block_auto' => false,
                'block_daily_cap' => 10,
            ],
            'rules' => $template === '' ? [] : [[
                'id' => null,
                'event' => TrafficEvent::Goal->value,
                'goal_code' => null,
                'needs_event' => '',
                'url_template' => $template,
                'enabled' => false,
            ]],
        ];
    }

    /**
     * A banner or a link bought at a fixed price on another site.
     *
     * @return array{source: array<string, mixed>, rules: list<array<string, mixed>>}
     */
    public static function forPlacement(): array
    {
        return [
            'source' => [
                'type' => TrafficSourceType::Placement->value,
                'name' => '',
                'link_key' => '',
                'preset_key' => null,
                'connector' => null,
                'status' => TrafficSourceStatus::Active->value,
                'attribution_days' => 7,
                'click_id_ttl_hours' => null,
                'target_cpa' => null,
                'reporting_timezone' => 'UTC',
                'link_params' => [],
                'response_rules' => null,
                'spend_mode' => TrafficSpendMode::Fixed->value,
                'spend_estimate' => null,
                'cost_unit' => TrafficCostUnit::Click->value,
                'rate_unit' => TrafficCostUnit::Click->value,
                'fixed_amount' => null,
                'fixed_period' => TrafficFixedPeriod::Month->value,
                'fixed_from' => null,
                'fixed_to' => null,
                'block_level' => TrafficBlockLevel::Site->value,
                'block_auto' => false,
                'block_daily_cap' => 10,
                'place_site' => null,
                'place_position' => null,
                'creatives' => [],
            ],
            'rules' => [],
        ];
    }

    /** @param  list<string>  $taken */
    private static function freeKey(string $key, array $taken): string
    {
        $candidate = $key;

        for ($n = 2; in_array($candidate, $taken, true); $n++) {
            $candidate = $key.$n;
        }

        return $candidate;
    }
}
