<?php

namespace App\Services\Traffic\Connectors;

use App\Enums\TrafficBlockLevel;
use App\Enums\TrafficConnectorFailure;
use App\Support\Traffic\Money\TrafficMoney;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * ExoClick's API (https://api.exoclick.com/v2): an API token signs in for a
 * session, kept with the token in the connection's encrypted credentials and
 * signed in again once on a 401. Spend from `statistics/a/global` by day,
 * campaign and zone, checked against its own total; campaigns from
 * `campaigns`; zones blocked by rewriting a campaign's blocked zones — the
 * API cannot block a site. A dollar account only. Unverified until checked
 * on the owner's token.
 */
final class ExoClickConnector implements EditsBlockLists, ReadsCampaigns, ReadsSpend, TrafficNetworkConnector
{
    use CallsNetworkApi;

    public const string BASE = 'https://api.exoclick.com/v2';

    private const int PAGE = 500;

    private const int MOST_ZONES = 2500;

    public function verify(ConnectorSession $session): ConnectorAccount
    {
        $user = $this->json($this->authed($session, fn (PendingRequest $client) => $client->get(self::BASE.'/user')));
        $data = is_array($user['result'] ?? null) ? $user['result'] : $user;
        $currency = strtoupper(trim((string) ($data['currency'] ?? $data['account']['currency'] ?? '')));

        // An answer that doesn't say the account's currency proves nothing about it: its spend is never taken for dollars.
        if ($currency === '') {
            throw new ConnectorException(TrafficConnectorFailure::UnexpectedResponse);
        }

        if ($currency !== 'USD') {
            throw new ConnectorException(TrafficConnectorFailure::Currency);
        }

        return new ConnectorAccount((string) ($data['username'] ?? $data['email'] ?? 'ExoClick account'), $currency);
    }

    public function spendForDay(ConnectorSession $session, string $networkDay, array $campaignIds): SpendDayReport
    {
        if ($campaignIds === []) {
            return new SpendDayReport([], true);
        }

        $rows = [];
        $total = null;

        for ($offset = 0; ; $offset += self::PAGE) {
            $page = $this->json($this->authed($session, fn (PendingRequest $client) => $client->post(self::BASE.'/statistics/a/global', [
                'group_by' => ['date', 'campaign_id', 'zone_id'],
                'filter' => ['date_from' => $networkDay, 'date_to' => $networkDay, 'campaign_id' => array_map('intval', $campaignIds), 'exclude_deleted' => 0],
                'projection' => ['base'],
                'totals' => 1,
                'limit' => self::PAGE,
                'offset' => $offset,
                'order_by' => [['field' => 'campaign_id', 'order' => 'asc'], ['field' => 'zone_id', 'order' => 'asc']],
            ])));
            $result = array_values(array_filter((array) ($page['result'] ?? []), 'is_array'));
            $total ??= isset($page['result_total']['cost']) ? (float) $page['result_total']['cost'] : null;

            foreach ($result as $row) {
                $rows[] = [
                    'campaign' => (string) ($row['campaign_id'] ?? ''),
                    'site' => '',
                    'zone' => (string) ($row['zone_id'] ?? ''),
                    'amount' => TrafficMoney::stored($row['cost'] ?? 0),
                    'clicks' => isset($row['clicks']) ? (int) $row['clicks'] : null,
                    'impressions' => isset($row['impressions']) ? (int) $row['impressions'] : null,
                ];
            }

            if (count($result) < self::PAGE) {
                break;
            }
        }

        $sum = array_sum(array_map(fn (array $row): float => (float) $row['amount'], $rows));
        $closed = CarbonImmutable::parse($networkDay, 'America/New_York')->addDay()->isPast();
        $tolerance = $closed ? 0.01 : max(0.01, ($total ?? 0) * 0.01);

        return new SpendDayReport($rows, $total !== null && abs($total - $sum) <= $tolerance);
    }

    public function campaigns(ConnectorSession $session, array $campaignIds): array
    {
        if ($campaignIds === []) {
            return [];
        }

        // A few dozen IDs a request, so the address stays well within what a server takes.
        $found = [];

        foreach (array_chunk($campaignIds, 50) as $chunk) {
            $query = implode('&', [...array_map(fn (string $id): string => 'ids[]='.rawurlencode($id), $chunk), 'detailed=true']);
            $json = $this->json($this->authed($session, fn (PendingRequest $client) => $client->get(self::BASE.'/campaigns?'.$query)));
            $found = [...$found, ...array_values(array_filter((array) ($json['result'] ?? []), 'is_array'))];
        }

        return array_map(function (array $campaign): NetworkCampaign {
            $model = match ((int) ($campaign['pricing_model'] ?? 0)) {
                1, 7 => 'click',
                2, 4 => 'cpm',
                default => null,
            };
            $price = $model === null || ! isset($campaign['price']) ? null : TrafficMoney::stored($campaign['price'], 6);

            return new NetworkCampaign(
                id: (string) ($campaign['id'] ?? ''),
                name: isset($campaign['name']) ? (string) $campaign['name'] : null,
                countries: isset($campaign['countries']) ? array_values(array_map(fn ($code): string => strtoupper((string) (is_array($code) ? ($code['code'] ?? '') : $code)), (array) $campaign['countries'])) : null,
                format: isset($campaign['ad_format']) ? (string) $campaign['ad_format'] : null,
                bidModel: $model,
                bidMobile: $price,
                bidDesktop: $price,
                dailyBudget: (int) ($campaign['daily_limit_type'] ?? 0) === 1 && isset($campaign['max_daily_budget_reset']) ? TrafficMoney::stored($campaign['max_daily_budget_reset'], 2) : null,
            );
        }, $found);
    }

    public function blockLevels(): array
    {
        return [TrafficBlockLevel::Zone];
    }

    public function applyBlockDelta(ConnectorSession $session, string $campaignId, TrafficBlockLevel $level, array $add, array $remove): BlockDeltaResult
    {
        if ($level !== TrafficBlockLevel::Zone) {
            throw new ConnectorException(TrafficConnectorFailure::Unsupported);
        }

        $zones = $this->blockedZones($session, $campaignId);
        $list = array_values(array_diff(array_unique([...$zones, ...$add]), $remove));

        if (count($list) > self::MOST_ZONES) {
            throw new ConnectorException(TrafficConnectorFailure::LimitReached);
        }

        $response = $this->authed($session, fn (PendingRequest $client) => $client->put(self::BASE.'/campaigns/'.rawurlencode($campaignId), [
            'zone_targeting' => ['type' => 0],
            'zones' => array_map('intval', $list),
        ]));
        $listed = $this->blockedZones($session, $campaignId);

        return new BlockDeltaResult(array_diff($add, $listed) === [] && array_intersect($remove, $listed) === [], $response->status(), $listed);
    }

    /**
     * The campaign's blocked zones. A list that isn't there, or a zone in it
     * without a numeric ID, fails the change: rewriting the list from it
     * would drop zones blocked already. An empty list is a list.
     *
     * @return list<string>
     */
    private function blockedZones(ConnectorSession $session, string $campaignId): array
    {
        $json = $this->json($this->authed($session, fn (PendingRequest $client) => $client->get(self::BASE.'/campaigns/'.rawurlencode($campaignId).'?detailed=true')));
        $campaign = is_array($json['result'] ?? null) ? $json['result'] : $json;

        if ((int) ($campaign['zone_targeting']['type'] ?? 0) === 1) {
            throw new ConnectorException(TrafficConnectorFailure::Unsupported);
        }

        $zones = $campaign['zones'] ?? $campaign['zone_targeting']['zones'] ?? null;

        if (! is_array($zones)) {
            throw new ConnectorException(TrafficConnectorFailure::UnexpectedResponse);
        }

        return array_values(array_map(function (mixed $zone): string {
            $id = is_array($zone) ? ($zone['idzone'] ?? $zone['id'] ?? null) : $zone;

            if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
                throw new ConnectorException(TrafficConnectorFailure::UnexpectedResponse);
            }

            return (string) $id;
        }, $zones));
    }

    /**
     * A call made with the session, signing in first when there is none or
     * it is about to end, and once more on a 401.
     *
     * @param  callable(PendingRequest): Response  $call
     */
    private function authed(ConnectorSession $session, callable $call): Response
    {
        $expires = $session->get('session_expires_at');

        if ($session->get('session_token') === null || $expires === null || CarbonImmutable::parse($expires)->subMinute()->isPast()) {
            $this->signIn($session);
        }

        try {
            return $this->call(fn (PendingRequest $client) => $call($client->withToken((string) $session->get('session_token'))));
        } catch (ConnectorException $exception) {
            if ($exception->httpStatus !== 401) {
                throw $exception;
            }

            $this->signIn($session);

            return $this->call(fn (PendingRequest $client) => $call($client->withToken((string) $session->get('session_token'))));
        }
    }

    private function signIn(ConnectorSession $session): void
    {
        $login = $this->json($this->call(fn (PendingRequest $client) => $client->post(self::BASE.'/login', ['api_token' => (string) $session->get('api_token')])));

        if (strcasecmp((string) ($login['type'] ?? ''), 'Bearer') !== 0 || ! isset($login['token'])) {
            throw new ConnectorException(TrafficConnectorFailure::TwoFactor);
        }

        $session->put('session_token', (string) $login['token']);
        $session->put('session_expires_at', CarbonImmutable::now()->addSeconds(min((int) ($login['expires_in'] ?? 3600), 3600))->toIso8601String());
    }
}
