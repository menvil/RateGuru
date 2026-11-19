<?php

namespace App\Filament\Pages\Analytics;

use App\Filament\Pages\GrowthAnalyticsPage;
use App\Queries\Analytics\JourneyQuery;
use App\Support\Analytics\AnalyticsFilter;
use App\Support\Analytics\JourneyTimeline;

/**
 * Analytics → Journeys, as the reference draws it: one visitor's or one
 * member's way through the site, looked up by an opaque ID — a browser by
 * `visitor_` and the first hex digits of its visitor hash, a member by
 * `user_` and the account's id, whose journey joins every browser it signed
 * up or signed in with. The search, the examples to pick — the latest
 * accounts and anonymous players, or with neither the latest visitors —
 * and, with nothing looked up, the first
 * example's journey; the steps in order beside Attribution and Identity
 * (JourneyTimeline). An ID several browsers share lists them to pick one;
 * one nobody has, or anything that is no ID, says so — names and emails
 * can't be searched. No filter of the page applies.
 *
 * Read with JourneyQuery; reads only. That a journey was looked up is the
 * page's to write down (LogJourneyLookupAction), once, as it is asked for.
 */
final class JourneysTab implements AnalyticsTab
{
    public function __construct(
        private readonly JourneyQuery $journeys,
        private readonly AnalyticsView $view,
    ) {}

    /** @return array<string, mixed> */
    public function data(GrowthAnalyticsPage $page, AnalyticsFilter $filter): array
    {
        $who = is_string($page->who) ? $page->who : '';
        $examples = $this->journeys->examples();
        $id = JourneyTimeline::read($who === '' ? ($examples[0] ?? null) : $who);
        $base = [
            'who' => $who,
            'examples' => array_map(fn (string $example): array => ['id' => $example, 'on' => $id !== null && JourneyTimeline::read($example) === $id], $examples),
        ];

        if ($who === '' && $examples === []) {
            return [...$base, 'state' => 'none'];
        }

        $found = $id === null ? null : $this->journeys->find($id);

        if ($found === null) {
            return [...$base, 'state' => 'missing', 'missing' => $id === null
                ? '“'.$who.'” isn’t a visitor_… or user_… ID. IDs are opaque; names and emails can’t be searched here.'
                : 'No first-party journey for “'.$who.'”. IDs are opaque; names and emails can’t be searched here.'];
        }

        if ($found['matches'] !== []) {
            $listed = array_slice($found['matches'], 0, JourneyTimeline::MOST_MATCHES);

            return [...$base, 'state' => 'matches', 'matches' => [
                'title' => (count($found['matches']) > count($listed) ? 'More than '.count($listed) : count($listed)).' browsers have IDs beginning '.JourneyTimeline::visitorId((string) $id['value'], strlen((string) $id['value'])),
                'note' => 'A visitor ID shows the first '.JourneyTimeline::VISITOR_DIGITS.' hex digits of a browser’s own. Pick one by more of them'.(count($found['matches']) > count($listed) ? ', or type more digits to narrow the list.' : '.'),
                'rows' => array_map(fn (string $hash): string => JourneyTimeline::visitorId($hash, JourneyTimeline::LONG_DIGITS), $listed),
            ]];
        }

        // The account's own records — signed-in attempts and versions, its sign-up and days — belong wherever its sign-up does.
        $journey = $this->journeys->journey(
            $found['browsers'],
            $found['account'] ? $found['user'] : null,
            $found['account'] ? $found['user'] : null,
        );
        $visits = [...$journey['visits'], ...$journey['credited'], ...($journey['first'] === null ? [] : [$journey['first']])];

        return [...$base, 'state' => 'journey', 'journey' => (new JourneyTimeline(
            ['kind' => $id['kind'], 'browsers' => $found['browsers'], 'user' => $found['user'], 'account' => $found['account'], 'more' => $found['more'], ...$journey],
            $this->view->sourceNames(array_column($visits, 'source')),
            $this->view->campaignNames(array_column($visits, 'campaign')),
        ))->view()];
    }
}
