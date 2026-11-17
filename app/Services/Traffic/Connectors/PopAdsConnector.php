<?php

namespace App\Services\Traffic\Connectors;

use App\Enums\TrafficBlockLevel;
use App\Enums\TrafficConnector;
use App\Enums\TrafficConnectorFailure;
use App\Support\Traffic\Money\TrafficMoney;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;

/**
 * PopAds' API (https://www.popads.net/docs/): the key as the `key`
 * parameter, JSON asked for, a second between calls. Spend from
 * `report_advertiser` in UTC, by campaign and website, checked against the
 * same day by campaign alone; campaigns from `apiv2/campaign/details`;
 * websites blocked through the campaign's website exclusion list, which
 * PopAds replaces whole. The report's fields are not documented: they are
 * read under the names third-party clients use, and the connector stays
 * unverified until a real answer has been read. The key opens the whole
 * account, money included — none of those calls is ever made.
 */
final class PopAdsConnector implements EditsBlockLists, ReadsCampaigns, ReadsSpend, TrafficNetworkConnector
{
    use CallsNetworkApi;

    public const string BASE = 'https://www.popads.net';

    public function verify(ConnectorSession $session): ConnectorAccount
    {
        $status = $this->json($this->get($session, '/api/user_status'));
        $label = (string) ($status['user']['username'] ?? $status['username'] ?? $status['email'] ?? 'PopAds account');

        return new ConnectorAccount($label, 'USD');
    }

    public function spendForDay(ConnectorSession $session, string $networkDay, array $campaignIds): SpendDayReport
    {
        if ($campaignIds === []) {
            return new SpendDayReport([], true);
        }

        $zone = TrafficConnector::PopAds->reportsIn();
        $next = CarbonImmutable::parse($networkDay, $zone)->addDay()->toDateString();
        $form = ['zone' => $zone, 'start' => $networkDay.' 00:00', 'end' => $next.' 00:00', 'campaigns' => implode(',', $campaignIds)];
        $rows = [];

        foreach ($this->reportRows($this->json($this->post($session, '/api/report_advertiser', [...$form, 'groups' => 'campaign,website,datetime:day']))) as $row) {
            $day = substr((string) ($row['datetime'] ?? $row['date'] ?? $networkDay), 0, 10);

            if ($day !== $networkDay) {
                continue;
            }

            $rows[] = [
                'campaign' => (string) ($row['campaign_id'] ?? $row['campaign'] ?? ''),
                'site' => (string) ($row['website_id'] ?? $row['website'] ?? ''),
                'zone' => '',
                'amount' => TrafficMoney::stored($row['cost'] ?? $row['spent'] ?? $row['amount'] ?? 0),
                'clicks' => null,
                'impressions' => isset($row['impressions']) ? (int) $row['impressions'] : null,
            ];
        }

        Sleep::for(1)->second();
        $totals = array_sum(array_map(fn (array $row): float => (float) ($row['cost'] ?? $row['spent'] ?? $row['amount'] ?? 0), $this->reportRows($this->json($this->post($session, '/api/report_advertiser', [...$form, 'groups' => 'campaign,datetime:day'])))));
        $sum = array_sum(array_map(fn (array $row): float => (float) $row['amount'], $rows));

        return new SpendDayReport($rows, abs($totals - $sum) < 0.01);
    }

    public function campaigns(ConnectorSession $session, array $campaignIds): array
    {
        $campaigns = [];

        foreach ($campaignIds as $index => $id) {
            if ($index > 0) {
                Sleep::for(1)->second();
            }

            // A campaign PopAds doesn't know — deleted there, a typo, a forged click — is left out; it says nothing about the key.
            try {
                $details = $this->json($this->get($session, '/apiv2/campaign/details/'.rawurlencode($id)));
            } catch (ConnectorException $exception) {
                if (in_array($exception->httpStatus, [403, 404], true)) {
                    continue;
                }

                throw $exception;
            }

            $data = is_array($details['data'] ?? null) ? $details['data'] : $details;
            $bid = isset($data['budget']['max_bid']) ? TrafficMoney::stored($data['budget']['max_bid'], 6) : null;
            $factors = array_map('strval', (array) ($data['device']['form_factor'] ?? []));

            $campaigns[] = new NetworkCampaign(
                id: (string) $id,
                name: isset($data['general_information']['name']) ? (string) $data['general_information']['name'] : null,
                countries: isset($data['countries']['codes']) ? array_values(array_map(fn ($code): string => strtoupper((string) $code), (array) $data['countries']['codes'])) : null,
                devices: $factors === [] ? null : array_values(array_unique(array_map(fn (string $factor): string => in_array(strtolower($factor), ['desktop', '1'], true) ? 'desktop' : 'mobile', $factors))),
                format: 'Popunder',
                // max_bid is the price of one popunder impression, which is one visit.
                bidModel: $bid === null ? null : 'click',
                bidMobile: $bid,
                bidDesktop: $bid,
                dailyBudget: isset($data['budget']['max_per_day']) ? TrafficMoney::stored($data['budget']['max_per_day'], 2) : null,
            );
        }

        return $campaigns;
    }

    public function blockLevels(): array
    {
        return [TrafficBlockLevel::Site];
    }

    public function applyBlockDelta(ConnectorSession $session, string $campaignId, TrafficBlockLevel $level, array $add, array $remove): BlockDeltaResult
    {
        if ($level !== TrafficBlockLevel::Site) {
            throw new ConnectorException(TrafficConnectorFailure::Unsupported);
        }

        $targeting = $this->json($this->get($session, '/api/campaign_website_targeting/'.rawurlencode($campaignId)));
        $mode = (string) ($targeting['mode'] ?? 'none');
        [$path, $items] = match (true) {
            $mode === 'global' && ($targeting['global_mode'] ?? null) === 'exclude' => ['/api/website_targeting/'.rawurlencode((string) $targeting['global_id']), (array) ($targeting['global_items'] ?? [])],
            in_array($mode, ['exclude', 'none'], true) => ['/api/campaign_website_targeting/'.rawurlencode($campaignId), (array) ($targeting['items'] ?? [])],
            default => throw new ConnectorException(TrafficConnectorFailure::Unsupported),
        };
        $list = array_values(array_diff(array_unique([...array_map('strval', $items), ...$add]), $remove));

        Sleep::for(1)->second();
        $response = $this->call(fn (PendingRequest $client) => $client
            ->withBody(implode("\n", $list), 'text/plain')
            ->post(self::BASE.$path.'?'.http_build_query(['key' => (string) $session->get('api_key'), 'mode' => 'exclude'])));
        $json = $response->json();
        $listed = array_map('strval', (array) (is_array($json) ? ($json['items'] ?? $json['global_items'] ?? $list) : $list));

        return new BlockDeltaResult(array_diff($add, $listed) === [] && array_intersect($remove, $listed) === [], $response->status(), array_values($listed));
    }

    /** @return array<int, array<string, mixed>> */
    private function reportRows(array $json): array
    {
        $rows = $json['rows'] ?? $json['data'] ?? $json;

        return array_values(array_filter((array) $rows, 'is_array'));
    }

    private function get(ConnectorSession $session, string $path): Response
    {
        return $this->call(fn (PendingRequest $client) => $client->get(self::BASE.$path, ['key' => (string) $session->get('api_key')]));
    }

    /** @param  array<string, string>  $form */
    private function post(ConnectorSession $session, string $path, array $form): Response
    {
        return $this->call(fn (PendingRequest $client) => $client->asForm()->post(self::BASE.$path.'?'.http_build_query(['key' => (string) $session->get('api_key')]), $form));
    }
}
