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
 * the campaign's targeting off instead — and read, a second between
 * campaigns, with nothing written, so the sync sees what was blocked in
 * PopAds' own panel. An error PopAds explains (a JSON
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

        foreach ($this->spendReport($session, [...$form, 'groups' => 'campaign,website,datetime:day']) as $row) {
            $day = substr((string) ($row['datetime'] ?? $row['date'] ?? $networkDay), 0, 10);

            if ($day !== $networkDay) {
                continue;
            }

            $rows[] = [
                'campaign' => (string) ($row['campaign_id'] ?? $row['campaign'] ?? ''),
                'site' => (string) ($row['website_id'] ?? $row['website'] ?? ''),
                'zone' => '',
                'amount' => TrafficMoney::stored($this->costOf($row)),
                'clicks' => null,
                'impressions' => isset($row['impressions']) ? $this->impressionCount($row['impressions']) : null,
            ];
        }

        Sleep::for(1)->second();
        $totals = array_sum(array_map(fn (array $row): float => (float) $this->costOf($row), $this->spendReport($session, [...$form, 'groups' => 'campaign,datetime:day'])));
        $sum = array_sum(array_map(fn (array $row): float => (float) $row['amount'], $rows));

        return new SpendDayReport($rows, abs($totals - $sum) < 0.01);
    }

    /**
     * A day's impressions by campaign, website and country, the country as a
     * two-letter code like a visit's. An answer that is no report, a count or
     * cost that is no figure, or a report with rows and not one this can read
     * is an answer it does not understand, never a day with no impressions,
     * so the figures already kept stay.
     */
    public function impressionsByCountry(ConnectorSession $session, string $networkDay, array $campaignIds): array
    {
        if ($campaignIds === []) {
            return [];
        }

        Sleep::for(1)->second();
        $reported = $this->report($session, [...$this->reportForm($networkDay, $campaignIds), 'groups' => 'country,campaign,website,datetime:day']);
        $ofDay = array_filter($reported, fn (array $row): bool => substr((string) ($row['datetime'] ?? $row['date'] ?? $networkDay), 0, 10) === $networkDay);
        $rows = [];

        foreach ($ofDay as $row) {
            $country = strtoupper((string) ($row['country'] ?? ''));

            // A row of no country no visit could match. One of a country without its count would leave that country's figures out of the day.
            if (preg_match('/\A[A-Z]{2}\z/', $country) !== 1) {
                continue;
            }

            $rows[] = [
                'campaign' => (string) ($row['campaign_id'] ?? $row['campaign'] ?? ''),
                'site' => (string) ($row['website_id'] ?? $row['website'] ?? ''),
                'country' => $country,
                'impressions' => $this->impressionCount($row['impressions'] ?? null),
                'amount' => TrafficMoney::stored($this->costOf($row)),
            ];
        }

        if ($rows === [] && $ofDay !== []) {
            throw new ConnectorException(TrafficConnectorFailure::UnexpectedResponse);
        }

        return $rows;
    }

    /**
     * A report's rows, bare or under `rows` or `data`, null there being none.
     * An answer that is no report is never read as a day with nothing in it:
     * the figures already kept would be replaced by none.
     *
     * @param  array<string, string>  $form
     * @return array<int, array<string, mixed>>
     *
     * @throws ConnectorException
     */
    private function report(ConnectorSession $session, array $form): array
    {
        $answer = $this->json($this->post($session, '/api/report_advertiser', $form));

        if ($this->listsErrors($answer)) {
            throw new ConnectorException(TrafficConnectorFailure::Rejected);
        }

        $rows = match (true) {
            array_key_exists('rows', $answer) => $answer['rows'] ?? [],
            array_key_exists('data', $answer) => $answer['data'] ?? [],
            default => $answer,
        };

        if (array_key_exists('errors', $answer) || ! is_array($rows) || array_filter($rows, 'is_array') !== $rows) {
            throw new ConnectorException(TrafficConnectorFailure::UnexpectedResponse);
        }

        return array_values($rows);
    }

    /**
     * A report read for its spend, every row with its cost: stored without
     * one, the day would be kept at part of what it cost.
     *
     * @param  array<string, string>  $form
     * @return array<int, array<string, mixed>>
     *
     * @throws ConnectorException
     */
    private function spendReport(ConnectorSession $session, array $form): array
    {
        $rows = $this->report($session, $form);

        foreach ($rows as $row) {
            $this->costOf($row);
        }

        return $rows;
    }

    /**
     * What a row cost, zero included. A row with no figure says nothing about
     * it, least of all that it cost nothing.
     *
     * @param  array<string, mixed>  $row
     *
     * @throws ConnectorException
     */
    private function costOf(array $row): int|float|string
    {
        $cost = $row['cost'] ?? $row['spent'] ?? $row['amount'] ?? null;

        if (! is_numeric($cost)) {
            throw new ConnectorException(TrafficConnectorFailure::UnexpectedResponse);
        }

        return $cost;
    }

    /**
     * Whether PopAds explains a refusal in an `errors` list.
     *
     * @param  array<mixed>  $answer
     */
    private function listsErrors(array $answer): bool
    {
        return is_array($answer['errors'] ?? null) && $answer['errors'] !== [];
    }

    /** A whole number of impressions, zero included, as a number or in a string. */
    private function impressionCount(mixed $value): int
    {
        $count = is_numeric($value) ? (float) $value : -1.0;

        if ($count < 0 || is_infinite($count) || floor($count) !== $count) {
            throw new ConnectorException(TrafficConnectorFailure::UnexpectedResponse);
        }

        return (int) $count;
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

    public function blockLists(ConnectorSession $session, array $campaignIds, TrafficBlockLevel $level): BlockListsRead
    {
        if ($level !== TrafficBlockLevel::Site) {
            throw new ConnectorException(TrafficConnectorFailure::Unsupported);
        }

        $lists = [];
        $failure = null;

        foreach ($campaignIds as $index => $id) {
            if ($index > 0) {
                Sleep::for(1)->second();
            }

            // A campaign PopAds doesn't know, or one listing sites to allow, has no list of sites to block.
            try {
                [, $items, $mode] = $this->exclusionList($session, $id);
            } catch (ConnectorException $exception) {
                if ($exception->failure === TrafficConnectorFailure::Unsupported || in_array($exception->httpStatus, [403, 404], true)) {
                    continue;
                }

                if ($exception->failure->needsAttention()) {
                    throw $exception->forCampaign($id);
                }

                // The sync says whose list it couldn't read; the other campaigns' are read all the same, unless PopAds is down, slow or busy.
                $failure ??= $exception->forCampaign($id);

                if ($exception->failure->passes()) {
                    break;
                }

                continue;
            }

            // Targeting turned off excludes nothing, whatever sites it still keeps.
            $lists[$id] = $mode === 'none' ? [] : array_values(array_unique($items));
        }

        return new BlockListsRead($lists, $failure);
    }

    public function applyBlockDelta(ConnectorSession $session, string $campaignId, TrafficBlockLevel $level, array $add, array $remove): BlockDeltaResult
    {
        if ($level !== TrafficBlockLevel::Site) {
            throw new ConnectorException(TrafficConnectorFailure::Unsupported);
        }

        [$path, $items, $mode] = $this->exclusionList($session, $campaignId);
        $list = array_values(array_diff(array_unique([...$items, ...$add]), $remove));

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

    /**
     * A campaign's exclusion list as PopAds has it — its own, or the one it
     * shares through `global` targeting — with where it is written and the
     * campaign's mode; none while its targeting is off. A list read without
     * its sites, or an answer without the mode, is never taken for an empty
     * one: rewritten from it, the sites it held would be lost.
     *
     * @return array{0: string, 1: list<string>, 2: string} where it is written, its sites, the mode
     *
     * @throws ConnectorException
     */
    private function exclusionList(ConnectorSession $session, string $campaignId): array
    {
        $targeting = $this->json($this->get($session, '/api/campaign_website_targeting/'.rawurlencode($campaignId)));
        $mode = $targeting['mode'] ?? null;

        // An answer that doesn't say how the campaign targets sites says nothing of its list: never taken for targeting turned off.
        if (! is_string($mode)) {
            throw new ConnectorException(TrafficConnectorFailure::UnexpectedResponse);
        }

        [$path, $items] = match (true) {
            $mode === 'global' && ($targeting['global_mode'] ?? null) === 'exclude' => ['/api/website_targeting/'.rawurlencode((string) $targeting['global_id']), $targeting['global_items'] ?? null],
            $mode === 'exclude' => ['/api/campaign_website_targeting/'.rawurlencode($campaignId), $targeting['items'] ?? null],
            $mode === 'none' => ['/api/campaign_website_targeting/'.rawurlencode($campaignId), (array) ($targeting['items'] ?? [])],
            default => throw new ConnectorException(TrafficConnectorFailure::Unsupported),
        };

        if (! is_array($items)) {
            throw new ConnectorException(TrafficConnectorFailure::UnexpectedResponse);
        }

        return [$path, array_values(array_map('strval', $items)), $mode];
    }

    /**
     * An error PopAds explains is a request it refused, whatever its status — 500 for a list it won't take — never an
     * API that is down, nor a refused key: PopAds refuses a key with a page, not an explained error.
     */
    private function failureOf(Response $response): TrafficConnectorFailure
    {
        $json = $response->json();

        return match (true) {
            is_array($json) && $this->listsErrors($json) => TrafficConnectorFailure::Rejected,
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
