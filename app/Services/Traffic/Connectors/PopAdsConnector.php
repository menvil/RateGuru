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
 * PopAds replaces whole — and refuses empty: the last site taken off turns
 * the campaign's targeting off instead. An error PopAds explains (a JSON
 * `errors` list, sent with HTTP 500 for a request it refused) is a refusal,
 * not an API that is down. The report's fields are not documented: they are
 * read under the names third-party clients use, and the connector stays
 * unverified until a real answer has been read. The key opens the whole
 * account, money included — none of those calls is ever made.
 */
final class PopAdsConnector implements EditsBlockLists, ReadsCampaigns, ReadsImpressionsByCountry, ReadsSpend, TrafficNetworkConnector
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

        $form = $this->reportForm($networkDay, $campaignIds);
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

    /**
     * A day's impressions by campaign, website and country, the country as a
     * two-letter code like a visit's. A report with rows and not one this can
     * read is an answer it does not understand, never a day with no
     * impressions, so the figures already kept stay.
     */
    public function impressionsByCountry(ConnectorSession $session, string $networkDay, array $campaignIds): array
    {
        if ($campaignIds === []) {
            return [];
        }

        Sleep::for(1)->second();
        $reported = $this->reportRows($this->json($this->post($session, '/api/report_advertiser', [...$this->reportForm($networkDay, $campaignIds), 'groups' => 'country,campaign,website,datetime:day'])));
        $ofDay = array_filter($reported, fn (array $row): bool => substr((string) ($row['datetime'] ?? $row['date'] ?? $networkDay), 0, 10) === $networkDay);
        $rows = [];

        foreach ($ofDay as $row) {
            $country = strtoupper((string) ($row['country'] ?? ''));

            if (preg_match('/\A[A-Z]{2}\z/', $country) !== 1 || ! isset($row['impressions'])) {
                continue;
            }

            $rows[] = [
                'campaign' => (string) ($row['campaign_id'] ?? $row['campaign'] ?? ''),
                'site' => (string) ($row['website_id'] ?? $row['website'] ?? ''),
                'country' => $country,
                'impressions' => (int) $row['impressions'],
                'amount' => TrafficMoney::stored($row['cost'] ?? $row['spent'] ?? $row['amount'] ?? 0),
            ];
        }

        if ($rows === [] && $ofDay !== []) {
            throw new ConnectorException(TrafficConnectorFailure::UnexpectedResponse);
        }

        return $rows;
    }

    /**
     * What every report of one day is asked for, in the time zone PopAds reports in.
     *
     * @param  list<string>  $campaignIds
     * @return array<string, string>
     */
    private function reportForm(string $networkDay, array $campaignIds): array
    {
        $zone = TrafficConnector::PopAds->reportsIn();
        $next = CarbonImmutable::parse($networkDay, $zone)->addDay()->toDateString();

        return ['zone' => $zone, 'start' => $networkDay.' 00:00', 'end' => $next.' 00:00', 'campaigns' => implode(',', $campaignIds)];
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
            $mode === 'global' && ($targeting['global_mode'] ?? null) === 'exclude' => ['/api/website_targeting/'.rawurlencode((string) $targeting['global_id']), $targeting['global_items'] ?? null],
            $mode === 'exclude' => ['/api/campaign_website_targeting/'.rawurlencode($campaignId), $targeting['items'] ?? null],
            $mode === 'none' => ['/api/campaign_website_targeting/'.rawurlencode($campaignId), (array) ($targeting['items'] ?? [])],
            default => throw new ConnectorException(TrafficConnectorFailure::Unsupported),
        };

        // An exclusion list read without its sites is never rewritten: the sites it already held would be lost.
        if (! is_array($items)) {
            throw new ConnectorException(TrafficConnectorFailure::UnexpectedResponse);
        }

        $list = array_values(array_diff(array_unique([...array_map('strval', $items), ...$add]), $remove));

        // A list to exclude needs a site: the campaign's own, emptied, is its targeting turned off; a list other campaigns share can't be turned off for one.
        if ($list === [] && $mode === 'global') {
            throw new ConnectorException(TrafficConnectorFailure::SharedList);
        }

        Sleep::for(1)->second();
        $response = $this->call(fn (PendingRequest $client) => $client
            ->withBody(implode("\n", $list), 'text/plain')
            ->post(self::BASE.$path.'?'.http_build_query(['key' => (string) $session->get('api_key'), 'mode' => $list === [] ? 'none' : 'exclude'])));
        $json = $response->json();
        $listed = is_array($json) ? ($json['items'] ?? $json['global_items'] ?? (($json['mode'] ?? null) === 'none' ? [] : null)) : null;

        // An answer without the list proves nothing: the list is read back, never taken to be the one sent — nor an empty one when the read back has none.
        if ($listed === null) {
            Sleep::for(1)->second();
            $again = $this->json($this->get($session, '/api/campaign_website_targeting/'.rawurlencode($campaignId)));
            $listed = match (true) {
                ($again['mode'] ?? null) === 'none' => [],
                $mode === 'global' => $again['global_items'] ?? null,
                default => $again['items'] ?? null,
            };

            if (! is_array($listed)) {
                throw new ConnectorException(TrafficConnectorFailure::UnexpectedResponse);
            }
        }

        $listed = array_map('strval', (array) $listed);

        return new BlockDeltaResult(array_diff($add, $listed) === [] && array_intersect($remove, $listed) === [], $response->status(), array_values($listed));
    }

    /** @return array<int, array<string, mixed>> */
    private function reportRows(array $json): array
    {
        $rows = $json['rows'] ?? $json['data'] ?? $json;

        return array_values(array_filter((array) $rows, 'is_array'));
    }

    /**
     * An error PopAds explains is a request it refused, whatever its status — 500 for a list it won't take — never an
     * API that is down, nor a refused key: PopAds refuses a key with a page, not an explained error.
     */
    private function failureOf(Response $response): TrafficConnectorFailure
    {
        $json = $response->json();

        return match (true) {
            is_array($json) && is_array($json['errors'] ?? null) && $json['errors'] !== [] => TrafficConnectorFailure::Rejected,
            in_array($response->status(), [401, 403], true) => TrafficConnectorFailure::Auth,
            $response->status() === 429 => TrafficConnectorFailure::RateLimited,
            $response->serverError() => TrafficConnectorFailure::Unavailable,
            default => TrafficConnectorFailure::Rejected,
        };
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
