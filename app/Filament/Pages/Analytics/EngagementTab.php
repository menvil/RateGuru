<?php

namespace App\Filament\Pages\Analytics;

use App\Enums\AnalyticsDimension;
use App\Enums\PostStatus;
use App\Filament\Pages\GrowthAnalyticsPage;
use App\Filament\Pages\TrafficSourcesPage;
use App\Models\Post;
use App\Models\TrafficSetting;
use App\Queries\Analytics\AnalyticsContentQuery;
use App\Queries\Analytics\AnalyticsVisitsQuery;
use App\Support\Analytics\ActivityDay;
use App\Support\Analytics\AnalyticsFilter;
use App\Support\Analytics\AnalyticsPeriod;
use App\Support\Analytics\AnalyticsSource;
use App\Support\Analytics\CampaignKey;
use App\Support\Analytics\FigureChange;
use App\Support\Analytics\VisitorFigures;

/**
 * Analytics → Site engagement, as the reference draws it: everyone who came
 * to any page of the site, games or not, and what they did there — the
 * visitors, those who came back, the posts they opened and the browsers that
 * opened one, the votes, comments and shares they made, the accounts and the
 * activated ones — each against the window before but the post viewers; the
 * website funnel from a visit to an activated user; two sources side by side,
 * of the period and the device alone, picked from the six the most people
 * came from; and the posts opened most, of the period and the device alone
 * too, each with the accounts whose session opened it first.
 *
 * A browser's posts are kept 90 days (`traffic_visit_posts`), so a period
 * reaching further back shows no post figure at all rather than a part of
 * one, and says why; an earlier window that reaches further back gives them
 * no change. While Record site visits is off only the accounts are shown, the
 * game visits and paid clicks recording them, with what is off said once.
 * Nobody matching the filters is said once, with nothing else drawn.
 */
final class EngagementTab implements AnalyticsTab
{
    /** How many sources Compare sources offers on each side, the most people first. */
    public const int COMPARED_SOURCES = 6;

    /** How many posts Content engagement lists, the most opened first. */
    public const int POSTS = 10;

    public const string POSTS_KEPT = 'Post views are kept for 90 days';

    public const string SITE_OFF = 'Record site visits is off, so the posts people open and what they do on the site aren’t counted. Only the accounts are shown.';

    public function __construct(
        private readonly AnalyticsVisitsQuery $visits,
        private readonly AnalyticsContentQuery $content,
        private readonly AnalyticsView $view,
    ) {}

    /** @return array<string, mixed> */
    public function data(GrowthAnalyticsPage $page, AnalyticsFilter $filter): array
    {
        $period = $filter->period;
        $flags = TrafficSetting::captureFlags();
        $state = [
            'range' => $period->range(),
            'filtered' => $filter->device !== null || $filter->narrowsSource(),
            // Without site visits there is nothing of the site to show but the accounts the game visits and paid clicks bring;
            // while nothing is recorded at all, the page's own notice says so.
            'siteOff' => ! $flags['site'],
            'notice' => $flags['capture'] && ! $flags['site'] ? ['text' => self::SITE_OFF, 'url' => TrafficSourcesPage::getUrl(['tab' => 'sources'])] : null,
        ];
        $figures = $this->visits->figures($filter);

        if ($figures->isEmpty()) {
            return ['empty' => true, ...$state];
        }

        $previousFilter = $filter->previous();
        $previous = $previousFilter === null ? null : $this->visits->figures($previousFilter);
        $change = fn (int $now, ?int $before): ?string => FigureChange::between($now, $before, $previous === null ? null : $period->comparison())?->text;

        if ($state['siteOff']) {
            return ['empty' => false, ...$state, 'cards' => self::accountCards($figures, $previous, $period, $change)];
        }

        // Posts are kept back to a moment of the day 90 days ago: the days after it are the oldest still whole.
        $keptSince = $period->today->subDays((int) config('traffic.retention_days.visit_details') - 1)->toDateString();
        $kept = $period->fromDay >= $keptSince;
        $posts = $kept ? $this->content->figures($filter) : null;
        $postsBefore = $previousFilter !== null && $previousFilter->fromDay() >= $keptSince ? $this->content->figures($previousFilter) : null;

        return [
            'empty' => false,
            ...$state,
            'scope' => 'All visitors of the site, including people who never open a game. '.$this->scope($filter).'.',
            'cards' => $this->cards($filter, $figures, $previousFilter, $previous, $posts, $postsBefore, $change),
            'funnel' => self::funnel($figures, $posts['viewers'] ?? null),
            'compare' => $this->compare($page, $filter->periodAndDevice(), $kept),
            'content' => $this->content($filter, $kept),
        ];
    }

    /**
     * The nine tiles: the visitors and the returning ones, the post views
     * and viewers, the votes, comments and shares, the accounts and the
     * activated ones — every one but the post viewers against the window
     * before. The post figures are none for a period older than the posts
     * are kept, and have no change against a window older than that.
     *
     * @param  array{views: int, viewers: int, voters: int, shares: int}|null  $posts  none while the period's posts aren't all kept
     * @param  array{views: int, viewers: int, voters: int, shares: int}|null  $postsBefore  none while the window before's aren't
     * @param  callable(int, ?int): ?string  $change
     * @return list<array{label: string, value: string, unit: string, tone: string, note: string, flag: array{label: string, hint: string}|null}>
     */
    private function cards(AnalyticsFilter $filter, VisitorFigures $now, ?AnalyticsFilter $previousFilter, ?VisitorFigures $before, ?array $posts, ?array $postsBefore, callable $change): array
    {
        $returning = $this->visits->returning($filter);
        $returnedBefore = $previousFilter === null ? null : $this->visits->returning($previousFilter);
        $shares = $posts === null ? null : $posts['shares'] + $this->visits->resultShares($filter)['clicks'];
        $sharesBefore = $postsBefore === null || $previousFilter === null ? null : $postsBefore['shares'] + $this->visits->resultShares($previousFilter)['clicks'];
        $note = fn (?string ...$parts): string => implode(' · ', array_filter($parts, fn (?string $part): bool => $part !== null && $part !== ''));

        return [
            self::card('Unique visitors', self::count($now->visitors), 'Visitors', $note($change($now->visitors, $before?->visitors))),
            self::card('Returning visitors', self::count($returning), 'Visitors', $note(self::percent(VisitorFigures::rate($returning, $now->visitors)).' of visitors', $change($returning, $returnedBefore))),
            $posts === null
                ? self::card('Post views', '—', 'Actions', self::POSTS_KEPT)
                : self::card('Post views', self::count($posts['views']), 'Actions', $note(
                    $posts['viewers'] === 0 ? null : number_format($posts['views'] / $posts['viewers'], 1).' per viewer',
                    $change($posts['views'], $postsBefore['views'] ?? null),
                )),
            $posts === null
                ? self::card('Unique post viewers', '—', 'Visitors', self::POSTS_KEPT)
                : self::card('Unique post viewers', self::count($posts['viewers']), 'Visitors', self::percent(VisitorFigures::rate($posts['viewers'], $now->visitors)).' of visitors'),
            self::card('Votes cast', self::count($now->votes), 'Actions', $note('By '.self::count($now->voters).' '.str('voter')->plural($now->voters), $change($now->votes, $before?->votes))),
            self::card('Comments created', self::count($now->comments), 'Actions', $note('By '.self::count($now->commenters).' '.str('commenter')->plural($now->commenters), $change($now->comments, $before?->comments))),
            $shares === null
                ? self::card('Shares initiated', '—', 'Actions', self::POSTS_KEPT)
                : self::card('Shares initiated', self::count($shares), 'Actions', $note('Share clicks, not confirmed posts', $change($shares, $sharesBefore))),
            ...self::accountCards($now, $before, $filter->period, $change),
        ];
    }

    /**
     * The accounts and the activated ones against the window before, the
     * activated said too early while the period's last accounts can still
     * activate — as Overview's tiles say them.
     *
     * @param  callable(int, ?int): ?string  $change
     * @return list<array{label: string, value: string, unit: string, tone: string, note: string, flag: array{label: string, hint: string}|null}>
     */
    private static function accountCards(VisitorFigures $now, ?VisitorFigures $before, AnalyticsPeriod $period, callable $change): array
    {
        $matured = ActivityDay::maturedBefore($period->today, (int) config('analytics.activation_days'));

        return [
            self::card('Accounts created', self::count($now->accounts), 'Accounts', (string) $change($now->accounts, $before?->accounts)),
            self::card('Activated users', self::count($now->activated), 'Accounts', (string) $change($now->activated, $before?->activated),
                $period->toDay >= $matured->toDateString() ? ['label' => 'Too early', 'hint' => 'Accounts from '.$matured->format('d M').' on can still activate'] : null),
        ];
    }

    /**
     * The website funnel: each step's people, its share of the step before
     * and of the visitors — of the new visitors for the accounts and the
     * activated, since only they can become an account — and a bar against
     * the visitors. The post viewers are none while the posts of the period
     * aren't all kept.
     *
     * @return array{note: string, steps: list<array{name: string, hint: string, count: string, previous: string, first: string, bar: float, strong: bool}>}
     */
    private static function funnel(VisitorFigures $figures, ?int $viewers): array
    {
        $steps = [
            ['Website visit', $figures->visitors, 'Browsers with a visit to any page of the site'],
            ['Viewed a post', $viewers, 'Opened at least one post'],
            ['Saw the sign-up form', $figures->signupForm, 'Were shown the sign-up form, after a game or anywhere on the site'],
            ['Account created', $figures->accounts, 'Confirmed a new account'],
            ['Activated user', $figures->activated, 'Came back and did something within '.config('analytics.activation_days').' days'],
        ];

        return [
            'note' => 'Website visit → post → sign-up form → account. Each step counts unique visitors; the views, votes and comments in the cards above are counts of actions. Accounts and activations are shares of new visitors.'
                .($viewers === null ? ' '.self::POSTS_KEPT.'.' : ''),
            'steps' => array_map(function (int $index, array $step) use ($steps, $figures): array {
                [$name, $count, $hint] = $step;
                $earlier = $steps[$index - 1][1] ?? null;

                return [
                    'name' => $name,
                    'hint' => $hint,
                    'count' => $count === null ? '—' : self::count($count),
                    // The sign-up form is shown on any page, after a game too, so its visitors are no share of the post viewers.
                    'previous' => $count === null || $earlier === null || $index === 0 || $index === 2 ? '—' : self::percent(VisitorFigures::rate($count, $earlier)),
                    'first' => $count === null ? '—' : self::percent(VisitorFigures::rate($count, $index >= 3 ? $figures->newVisitors : $figures->visitors)),
                    'bar' => $count === null || $figures->visitors === 0 ? 0.0 : round(min(1, $count / $figures->visitors) * 100, 1),
                    'strong' => $index >= 3,
                ];
            }, array_keys($steps), $steps),
        ];
    }

    /**
     * Two sources side by side, of the period and the device alone: the
     * sources the most people came from to pick each side from — the
     * address's choice when it is one of them, else the biggest for A and
     * the second biggest for B, whatever the other side holds — and each
     * side's people, how many of them opened a post, voted on one they
     * opened, voted how often and commented, came back, and made or
     * activated an account of the new visitors. A share is "—" until what
     * it divides by is enough for a row; the better side of a row is the
     * stronger, and a sentence says what they differ in most plainly.
     *
     * @return array{note: string, pickers: list<array{key: string, property: string, options: list<array{key: string, label: string, title: string, on: bool}>}>, names: array{a: string, b: string}, rows: list<array{label: string, a: string, b: string, lead: string|null}>, answer: string}|null
     */
    private function compare(GrowthAnalyticsPage $page, AnalyticsFilter $base, bool $kept): ?array
    {
        $keys = array_values(array_filter(array_column($this->visits->top($base, AnalyticsDimension::Source, self::COMPARED_SOURCES), 'source_key'), 'is_string'));

        if ($keys === []) {
            return null;
        }

        $names = $this->view->sourceNames($keys);
        $askedA = in_array($page->a, $keys, true) ? (string) $page->a : null;
        $askedB = in_array($page->b, $keys, true) ? (string) $page->b : null;
        $a = $askedA ?? $keys[0];
        $b = $askedB ?? ($keys[1] ?? $keys[0]);
        $chosen = array_values(array_unique([$a, $b]));

        $figures = $this->visits->of($base, AnalyticsDimension::Source, $chosen);
        $posts = $kept ? $this->content->bySource($base, $chosen) : [];
        $returning = [];

        foreach ($chosen as $key) {
            $returning[$key] = $this->visits->returning(new AnalyticsFilter($base->period, $base->device, $key));
        }

        $minimum = (int) config('analytics.row_min_denominator');
        $side = function (string $key) use ($figures, $posts, $returning, $kept, $minimum): array {
            $people = $figures[$key]['figures'] ?? new VisitorFigures;
            $opened = $posts[$key] ?? ['views' => 0, 'viewers' => 0, 'voters' => 0, 'shares' => 0];

            return [
                'visitors' => $people->visitors,
                'viewed' => $kept ? VisitorFigures::rate($opened['viewers'], $people->visitors, $minimum) : null,
                'voted' => $kept ? VisitorFigures::rate($opened['voters'], $opened['viewers'], $minimum) : null,
                'votes' => VisitorFigures::rate($people->votes, $people->voters),
                'comments' => $kept ? self::perHundred(VisitorFigures::rate($people->comments, $opened['viewers'], $minimum)) : null,
                'returning' => VisitorFigures::rate($returning[$key] ?? 0, $people->visitors, $minimum),
                'registered' => $people->accountRate($minimum),
                'activated' => $people->activationRate($minimum),
            ];
        };
        $sideA = $side($a);
        $sideB = $side($b);
        $label = fn (string $key): string => AnalyticsSource::label($key, $names);
        // Two figures that read the same are a tie, however they differ underneath; rounding keeps the order of the rest.
        $line = fn (string $title, string $metric, callable $format): array => [
            'label' => $title,
            'a' => $sideA[$metric] === null ? '—' : $format($sideA[$metric]),
            'b' => $sideB[$metric] === null ? '—' : $format($sideB[$metric]),
            'lead' => $a === $b || $sideA[$metric] === null || $sideB[$metric] === null || $format($sideA[$metric]) === $format($sideB[$metric]) ? null : ($sideA[$metric] > $sideB[$metric] ? 'a' : 'b'),
        ];
        $average = fn (int|float $value): string => number_format((float) $value, 1);
        $share = fn (int|float $value): string => self::percent((float) $value);

        return [
            'note' => 'Same period and device, whatever source is chosen. Registration and activation use new visitors.',
            'pickers' => array_map(fn (string $tag, string $property, string $on): array => [
                'key' => $tag,
                'property' => $property,
                'options' => array_map(fn (string $key): array => ['key' => $key, 'label' => $label($key), 'title' => AnalyticsView::sourceLabel($key, $names), 'on' => $key === $on], $keys),
            ], ['A', 'B'], ['a', 'b'], [$a, $b]),
            'names' => ['a' => $label($a), 'b' => $label($b)],
            'rows' => [
                $line('Unique visitors', 'visitors', fn (int|float $value): string => self::count((int) $value)),
                $line('Viewed a post', 'viewed', $share),
                $line('Voted · of post viewers', 'voted', $share),
                $line('Votes per voter', 'votes', $average),
                $line('Comments per 100 viewers', 'comments', $average),
                $line('Returning visitors', 'returning', $share),
                $line('Registered · of new visitors', 'registered', $share),
                $line('Activated · of new visitors', 'activated', $share),
            ],
            'answer' => self::answer(count($keys), $label($a), $label($b), $a === $b, $sideA, $sideB),
        ];
    }

    /**
     * What the two sides differ in, said plainly: how often their post
     * viewers vote, or — where that can't be told — how often their new
     * visitors make an account.
     *
     * @param  array<string, float|int|null>  $a
     * @param  array<string, float|int|null>  $b
     */
    private static function answer(int $offered, string $nameA, string $nameB, bool $same, array $a, array $b): string
    {
        if ($offered < 2) {
            return 'Only '.$nameA.' brought visitors in these days, so there is nothing to compare it with yet.';
        }

        if ($same) {
            return 'Pick two different sources to compare.';
        }

        foreach ([['voted', 'vote', 'of post viewers voted'], ['registered', 'make an account', 'of new visitors made one']] as [$metric, $verb, $what]) {
            if ($a[$metric] === null || $b[$metric] === null) {
                continue;
            }

            // As often as the shares read: a difference the page doesn't show is none to say.
            if (self::percent((float) $a[$metric]) === self::percent((float) $b[$metric])) {
                return $nameA.' and '.$nameB.' visitors '.$verb.' as often: '.self::percent((float) $a[$metric]).' '.$what.'.';
            }

            [$more, $less, $high, $low] = $a[$metric] > $b[$metric] ? [$nameA, $nameB, $a[$metric], $b[$metric]] : [$nameB, $nameA, $b[$metric], $a[$metric]];

            return $more.' visitors '.$verb.' more often: '.self::percent((float) $high).' '.$what.', against '.self::percent((float) $low).' from '.$less.'.';
        }

        return 'Too few visitors from '.$nameA.' or '.$nameB.' to compare how they vote or sign up yet.';
    }

    /**
     * The posts opened most, of the period and the device alone: each with
     * its views, its viewers, the votes and voters, the comments, the share
     * of its viewers who voted or commented — "—" below the viewers a row
     * needs — and the accounts whose session opened it first; with the
     * accounts whose session opened none. A post deleted since reads as
     * removed, its id kept.
     *
     * @return array{note: string, kept: bool, rows: list<array<string, mixed>>, foot: string}
     */
    private function content(AnalyticsFilter $filter, bool $kept): array
    {
        $period = $filter->period;
        $note = 'Top posts by views · '.implode(' · ', array_filter([$period->isCustom() ? $period->range() : $period->label(), $filter->device?->label()]))
            .($filter->narrowsSource() ? '. Post-level numbers cover all sources; the source and campaign filters apply to the cards and funnel above.' : '.');

        if (! $kept) {
            return ['note' => $note, 'kept' => false, 'rows' => [], 'foot' => ''];
        }

        $minimum = (int) config('analytics.row_min_denominator');
        $top = $this->content->topPosts($period, $filter->device, self::POSTS);
        $ids = array_column($top, 'postId');
        $accounts = $this->content->firstPostAccounts($period, $filter->device, $ids);
        $unattributed = $this->content->unattributedAccounts($period, $filter->device);
        $posts = $this->content->posts($ids);

        $rows = array_map(function (array $row) use ($posts, $accounts, $minimum): array {
            $post = $posts->get($row['postId']);
            $removed = ! $post instanceof Post || $post->trashed();
            $engagement = VisitorFigures::rate($row['engaged'], $row['viewers'], $minimum);
            $registrations = $accounts[$row['postId']] ?? 0;

            return [
                'id' => $row['postId'],
                'title' => $removed ? 'Post removed' : (string) $post->title,
                'removed' => $removed,
                'url' => ! $removed && $post->status === PostStatus::Published ? route('posts.show', $row['postId']) : null,
                'views' => self::count($row['views']),
                'viewers' => self::count($row['viewers']),
                'votes' => self::count($row['votes']),
                'voters' => self::count($row['voters']).' '.str('voter')->plural($row['voters']),
                'comments' => self::count($row['comments']),
                'engagement' => $engagement === null ? '—' : self::percent($engagement),
                'engagementTitle' => $engagement === null ? null : self::count($row['engaged']).' of '.self::count($row['viewers']),
                'registrations' => self::count($registrations),
                'quiet' => $registrations === 0,
            ];
        }, $top);

        return [
            'note' => $note,
            'kept' => true,
            'rows' => $rows,
            'foot' => 'Engagement: unique viewers who voted or commented ÷ unique viewers, “—” until the viewers reach '.$minimum.'. '
                .'Votes and comments count the visits that voted on the post or commented on it. '
                .'Registrations: accounts made in a session whose first post opened was this one. '
                .'Not attributed: '.self::count($unattributed).' '.str('account')->plural($unattributed).', whose session opened no post.',
        ];
    }

    /** What the tab counts: the source and campaign chosen, or every source, the device, and the days. */
    private function scope(AnalyticsFilter $filter): string
    {
        $period = $filter->period;

        return implode(' · ', array_filter([
            $filter->sourceKey === null ? 'All sources' : AnalyticsSource::label($filter->sourceKey, $this->view->sourceNames([$filter->sourceKey])),
            $filter->campaignKey === null ? null : CampaignKey::label($filter->campaignKey, $this->view->campaignNames([$filter->campaignKey])),
            $filter->device?->label(),
            $period->isCustom() ? $period->range() : $period->label(),
        ]));
    }

    /**
     * @param  array{label: string, hint: string}|null  $flag
     * @return array{label: string, value: string, unit: string, tone: string, note: string, flag: array{label: string, hint: string}|null}
     */
    private static function card(string $label, string $value, string $unit, string $note, ?array $flag = null): array
    {
        return ['label' => $label, 'value' => $value, 'unit' => $unit, 'tone' => $unit === 'Actions' ? 'actions' : 'people', 'note' => $note, 'flag' => $flag];
    }

    private static function perHundred(?float $rate): ?float
    {
        return $rate === null ? null : $rate * 100;
    }

    private static function count(int $count): string
    {
        return number_format($count);
    }

    private static function percent(?float $rate): string
    {
        return $rate === null ? '—' : number_format($rate * 100, 1).'%';
    }
}
