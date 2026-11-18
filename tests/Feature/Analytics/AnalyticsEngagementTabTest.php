<?php

use App\Actions\Traffic\ConfirmAccountAttributionAction;
use App\Actions\Traffic\MarkTrafficVisitAction;
use App\Actions\Traffic\RecordAccountSignupAction;
use App\Enums\AccountSignupMethod;
use App\Enums\AccountSignupPlace;
use App\Enums\GameAudience;
use App\Enums\GameEventType;
use App\Enums\TrafficDevice;
use App\Enums\TrafficEvent;
use App\Filament\Pages\Analytics\AnalyticsView;
use App\Filament\Pages\Analytics\EngagementTab;
use App\Filament\Pages\GrowthAnalyticsPage;
use App\Models\GameAttempt;
use App\Models\GameEvent;
use App\Models\GameVersion;
use App\Models\Post;
use App\Models\TrafficVisit;
use App\Models\TrafficVisitPost;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * Analytics → Site engagement: everyone who came to any page and what they
 * did — nine tiles, each with the pill of what it counts and, but the post
 * viewers, its change against the window before; the website funnel, the
 * accounts and activations of new visitors; two sources side by side, of the
 * period and the device alone, picked from the six biggest and kept in the
 * address, the same source on both sides asking for two; and the posts
 * opened most, whatever source is chosen, each with the accounts whose
 * session opened it first, the rest not attributed, a deleted post removed.
 * Posts are kept 90 days, so an older period shows none of their figures and
 * says why; while Record site visits is off only the accounts are shown, with
 * the tab's own word about it. Nobody matching is said once; the page reads
 * no visit until it is in a browser, and the tab a bounded number of queries.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00'));
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
    recordTraffic(site: true);
});

/** The open Site engagement tab's figures, read as the page reads them once it is in a browser. */
function analyticsEngagement(array $query = []): array
{
    return Livewire::withQueryParams(['tab' => 'engagement', ...$query])->test(GrowthAnalyticsPage::class)->call('load')->viewData('data');
}

/** The tiles as text: the label, the value, the pill and the note. */
function engagementCards(array $data): array
{
    return array_map(fn (array $card): string => implode(' | ', [$card['label'], $card['value'], $card['unit'], $card['note']]), $data['cards']);
}

/**
 * Browsers that each came once from a source and opened the post given —
 * each one of them doing what the attributes given for its number say, on
 * its visit and on its row of the post, or opening none when they say so.
 *
 * @param  callable(int): array{visit?: array<string, mixed>, post?: array<string, mixed>|null}  $did
 */
function engagementVisitors(string $source, int $count, callable $did, int $postId = 1): void
{
    foreach (range(1, $count) as $n) {
        $what = $did($n);
        $visit = analyticsVisit($source.'-'.$n, ['source_key' => $source, ...($what['visit'] ?? [])]);

        if (! array_key_exists('post', $what) || $what['post'] !== null) {
            analyticsVisitPost($visit, $postId, $what['post'] ?? []);
        }
    }
}

it('draws the nine tiles with what each counts, every one but the post viewers against the window before', function () {
    // The window before: two people, a vote, two post views shared once, and an account.
    $before = analyticsVisit('cat', ['network_day' => '2026-10-01', 'votes' => 1, 'signup_at' => '2026-10-01 10:19:00', 'account_at' => '2026-10-01 10:20:00']);
    analyticsVisitPost($before, 1, ['views' => 2, 'shared_at' => '2026-10-01 10:05:00']);
    analyticsVisit('old', ['network_day' => '2026-10-02']);

    $ann = analyticsVisit('ann', ['votes' => 3, 'comments' => 2, 'signup_form_at' => '2026-11-15 10:18:00', 'signup_at' => '2026-11-15 10:19:00', 'account_at' => '2026-11-15 10:20:00', 'activated_at' => '2026-11-16 10:00:00']);
    analyticsVisitPost($ann, 1, ['views' => 4, 'voted' => true, 'shared_at' => '2026-11-15 10:05:00']);
    analyticsVisitPost($ann, 2, ['views' => 2]);
    analyticsVisitPost(analyticsVisit('bob'), 1, ['views' => 3]);
    // Seen before the period, and on two of its days: both come back.
    analyticsVisit('cat');
    analyticsVisit('dan', ['network_day' => '2026-11-12']);
    analyticsVisit('dan', ['network_day' => '2026-11-14']);
    // A result shared from a game played on Ann's visit is a share too.
    $attempt = GameAttempt::factory()->create(['version_id' => GameVersion::factory()->create()->id, 'traffic_visit_id' => $ann->id]);
    (new GameEvent)->forceFill(['game_id' => $attempt->game_id, 'version_id' => $attempt->version_id, 'attempt_id' => $attempt->id, 'audience' => GameAudience::Anonymous, 'visitor_hash' => $attempt->visitor_hash, 'type' => GameEventType::ShareClicked, 'provider' => 'reddit', 'occurred_at' => now()])->save();

    $data = analyticsEngagement();

    expect($data['scope'])->toBe('All visitors of the site, including people who never open a game. All sources · Last 30 days.')
        ->and(engagementCards($data))->toBe([
            'Unique visitors | 4 | Visitors | +100% vs the previous 30 days',
            'Returning visitors | 2 | Visitors | 50.0% of visitors · No earlier data',
            'Post views | 9 | Actions | 4.5 per viewer · +350% vs the previous 30 days',
            'Unique post viewers | 2 | Visitors | 50.0% of visitors',
            'Votes cast | 3 | Actions | By 1 voter · +200% vs the previous 30 days',
            'Comments created | 2 | Actions | By 1 commenter · No earlier data',
            'Shares initiated | 2 | Actions | Share clicks, not confirmed posts · +100% vs the previous 30 days',
            'Accounts created | 1 | Accounts | Same as the previous 30 days',
            'Activated users | 1 | Accounts | No earlier data',
        ])
        // Actions sit on a pill of their own.
        ->and(array_column($data['cards'], 'tone'))->toBe(['people', 'people', 'actions', 'people', 'actions', 'actions', 'actions', 'people', 'people'])
        // The period's last accounts can still activate.
        ->and($data['cards'][8]['flag'])->toBe(['label' => 'Too early', 'hint' => 'Accounts from 11 Nov on can still activate'])
        ->and(array_map(fn (array $step): string => implode(' | ', [$step['name'], $step['count'], $step['previous'], $step['first']]), $data['funnel']['steps']))->toBe([
            'Website visit | 4 | — | 100.0%',
            'Viewed a post | 2 | 50.0% | 50.0%',
            // The sign-up form is shown anywhere, so it is no share of the post viewers.
            'Saw the sign-up form | 1 | — | 25.0%',
            'Account created | 1 | 100.0% | 25.0%',
            'Activated user | 1 | 100.0% | 25.0%',
        ])
        ->and(array_column($data['funnel']['steps'], 'strong'))->toBe([false, false, false, true, true])
        ->and($data['funnel']['note'])->toBe('Website visit → post → sign-up form → account. Each step counts unique visitors; the views, votes and comments in the cards above are counts of actions. Accounts and activations are shares of new visitors.');

    Livewire::withQueryParams(['tab' => 'engagement'])->test(GrowthAnalyticsPage::class)->call('load')
        ->assertSee('Site engagement')
        ->assertSee('Website funnel')
        ->assertSeeHtml('rg-admin-analytics__unit rg-admin-analytics__unit--actions')
        ->assertSee('Share clicks, not confirmed posts');
});

it('counts the people of the source and campaign chosen, those who came only on the earlier day of a known browser among the new ones', function () {
    analyticsVisitPost(analyticsVisit('ann', ['source_key' => 'reddit:organic', 'campaign_key' => 'tag:spring', 'device' => TrafficDevice::Desktop]), 1, ['views' => 2]);
    analyticsVisitPost(analyticsVisit('bob', ['source_key' => 'reddit:organic']), 1, ['views' => 5]);
    analyticsVisit('cat', ['source_key' => 'google:organic', 'known' => true, 'account_at' => '2026-11-15 10:20:00']);

    $reddit = analyticsEngagement(['source' => 'reddit:organic']);
    $spring = analyticsEngagement(['source' => 'reddit:organic', 'campaign' => 'tag:spring', 'device' => 'desktop']);

    expect($reddit['scope'])->toBe('All visitors of the site, including people who never open a game. Reddit · Last 30 days.')
        ->and(array_slice(engagementCards($reddit), 0, 4))->toBe([
            'Unique visitors | 2 | Visitors | No earlier data',
            'Returning visitors | 0 | Visitors | 0.0% of visitors · No earlier data',
            'Post views | 7 | Actions | 3.5 per viewer · No earlier data',
            'Unique post viewers | 2 | Visitors | 100.0% of visitors',
        ])
        ->and($spring['scope'])->toBe('All visitors of the site, including people who never open a game. Reddit · spring · Desktop · Last 30 days.')
        ->and(array_slice(engagementCards($spring), 2, 1))->toBe(['Post views | 2 | Actions | 2.0 per viewer · No earlier data'])
        // A known browser's account is no share of the new visitors.
        ->and(array_column(analyticsEngagement(['source' => 'google:organic'])['funnel']['steps'], 'first'))->toBe(['100.0%', '0.0%', '0.0%', '—', '—']);
});

it('shows no post figure for a period older than the posts are kept, and no change against a window that is', function () {
    analyticsVisitPost(analyticsVisit('ann', ['network_day' => '2026-08-10']), 1, ['views' => 3]);
    analyticsVisitPost(analyticsVisit('bob', ['network_day' => '2026-11-15']), 1, ['views' => 2]);
    analyticsVisitPost(analyticsVisit('dan', ['network_day' => '2026-09-01']), 1, ['views' => 3]);
    analyticsVisitPost(analyticsVisit('cat', ['network_day' => '2026-06-01']), 1, ['views' => 6]);

    $old = analyticsEngagement(['from' => '2026-08-01', 'to' => '2026-08-30']);

    expect(engagementCards($old))->toContain('Post views | — | Actions | Post views are kept for 90 days')
        ->toContain('Unique post viewers | — | Visitors | Post views are kept for 90 days')
        ->toContain('Shares initiated | — | Actions | Post views are kept for 90 days')
        ->and($old['funnel']['steps'][1])->toMatchArray(['count' => '—', 'previous' => '—', 'first' => '—', 'bar' => 0.0])
        ->and($old['funnel']['note'])->toEndWith('Accounts and activations are shares of new visitors. Post views are kept for 90 days.')
        ->and($old['content']['kept'])->toBeFalse()
        ->and($old['content']['rows'])->toBe([])
        ->and(array_column($old['compare']['rows'], 'a', 'label'))->toMatchArray(['Unique visitors' => '1', 'Viewed a post' => '—', 'Voted · of post viewers' => '—', 'Comments per 100 viewers' => '—']);

    // Last 90 days is whole; the 90 days before it aren't, so its post figures have no change.
    $ninety = analyticsEngagement(['period' => '90d']);

    expect(engagementCards($ninety))->toContain('Post views | 5 | Actions | 2.5 per viewer')
        ->toContain('Unique visitors | 2 | Visitors | Same as the previous 90 days')
        ->and($ninety['content']['kept'])->toBeTrue();

    Livewire::withQueryParams(['tab' => 'engagement', 'from' => '2026-08-01', 'to' => '2026-08-30'])->test(GrowthAnalyticsPage::class)->call('load')
        ->assertSee(EngagementTab::POSTS_KEPT)
        ->assertSee('Pick a period within the last 90 days to see the posts people opened.');
});

it('compares two of the six biggest sources of the period and the device, kept in the address, and asks for two different ones', function () {
    // Reddit: forty people who all opened a post, twelve of them voted twice on it, six commented, four made an account and two activated it.
    engagementVisitors('reddit:organic', 40, fn (int $n): array => [
        'visit' => [
            'votes' => $n <= 12 ? 2 : 0,
            'comments' => $n > 34 ? 1 : 0,
            ...($n <= 4 ? ['signup_at' => '2026-11-15 10:19:00', 'account_at' => '2026-11-15 10:20:00', 'activated_at' => $n <= 2 ? '2026-11-16 10:00:00' : null] : []),
        ],
        'post' => ['voted' => $n <= 12],
    ]);
    // Google: thirty-two people, all opening a post, four voting once, one account.
    engagementVisitors('google:organic', 32, fn (int $n): array => [
        'visit' => ['votes' => $n <= 4 ? 1 : 0, ...($n === 1 ? ['account_at' => '2026-11-15 10:20:00'] : [])],
        'post' => ['voted' => $n <= 4],
    ]);
    engagementVisitors('email:email', 3, fn (int $n): array => ['post' => null]);
    // Of another device: never compared.
    engagementVisitors('bing:organic', 50, fn (int $n): array => ['visit' => ['device' => TrafficDevice::Desktop], 'post' => null]);

    $compare = analyticsEngagement(['device' => 'mobile', 'source' => 'email:email'])['compare'];

    expect(array_column($compare['pickers'][0]['options'], 'label'))->toBe(['Reddit', 'Google', 'Email'])
        ->and(array_column($compare['pickers'][0]['options'], 'title'))->toBe(['Reddit · organic_social', 'Google · organic_search', 'Email · email'])
        // The biggest on A, the next on B, whatever source the page is filtered by.
        ->and(array_column($compare['pickers'][0]['options'], 'on'))->toBe([true, false, false])
        ->and(array_column($compare['pickers'][1]['options'], 'on'))->toBe([false, true, false])
        ->and($compare['names'])->toBe(['a' => 'Reddit', 'b' => 'Google'])
        ->and(array_map(fn (array $row): string => implode(' | ', [$row['label'], $row['a'], $row['b'], $row['lead'] ?? '-']), $compare['rows']))->toBe([
            'Unique visitors | 40 | 32 | a',
            'Viewed a post | 100.0% | 100.0% | -',
            'Voted · of post viewers | 30.0% | 12.5% | a',
            'Votes per voter | 2.0 | 1.0 | a',
            'Comments per 100 viewers | 15.0 | 0.0 | a',
            'Returning visitors | 0.0% | 0.0% | -',
            'Registered · of new visitors | 10.0% | 3.1% | a',
            'Activated · of new visitors | 5.0% | 0.0% | a',
        ])
        ->and($compare['answer'])->toBe('Reddit visitors vote more often: 30.0% of post viewers voted, against 12.5% from Google.')
        ->and($compare['note'])->toBe('Same period and device, whatever source is chosen. Registration and activation use new visitors.');

    // Either side named in the address; the other keeps its own default, whatever the named side holds. Too few on a side to show its shares.
    $email = analyticsEngagement(['device' => 'mobile', 'b' => 'email:email'])['compare'];
    $sameAsB = analyticsEngagement(['device' => 'mobile', 'a' => 'google:organic'])['compare'];
    $sameAsA = analyticsEngagement(['device' => 'mobile', 'b' => 'reddit:organic'])['compare'];

    expect($email['names'])->toBe(['a' => 'Reddit', 'b' => 'Email'])
        ->and(array_column($email['rows'], 'b', 'label'))->toMatchArray(['Unique visitors' => '3', 'Viewed a post' => '—', 'Registered · of new visitors' => '—'])
        ->and($email['answer'])->toBe('Too few visitors from Reddit or Email to compare how they vote or sign up yet.')
        // Picking the other side's source asks for two rather than moving the other side.
        ->and($sameAsB['names'])->toBe(['a' => 'Google', 'b' => 'Google'])
        ->and($sameAsB['answer'])->toBe('Pick two different sources to compare.')
        ->and($sameAsA['names'])->toBe(['a' => 'Reddit', 'b' => 'Reddit'])
        ->and($sameAsA['answer'])->toBe('Pick two different sources to compare.')
        // A source not offered is the default.
        ->and(analyticsEngagement(['device' => 'mobile', 'a' => 'bing:organic'])['compare']['names'])->toBe(['a' => 'Reddit', 'b' => 'Google']);

    // The same source on both sides asks for two.
    $same = analyticsEngagement(['device' => 'mobile', 'a' => 'google:organic', 'b' => 'google:organic'])['compare'];

    expect($same['answer'])->toBe('Pick two different sources to compare.')
        ->and(array_unique(array_column($same['rows'], 'lead')))->toBe([null]);

    // A pick is kept in the address, and only on this tab; anything it can't read is the default.
    Livewire::withQueryParams(['tab' => 'engagement', 'device' => 'mobile'])->test(GrowthAnalyticsPage::class)->call('load')
        ->assertSeeHtml('aria-checked="true"')
        ->set('b', 'email:email')
        ->assertSet('b', 'email:email')
        ->assertViewHas('data', fn (array $data): bool => $data['compare']['names'] === ['a' => 'Reddit', 'b' => 'Email'])
        ->set('a', "reddit\n:organic")
        ->assertSet('a', '')
        ->set('tab', 'overview')
        ->assertSet('b', '');
});

it('says when there is only one source to compare', function () {
    analyticsVisit('ann', ['source_key' => 'reddit:organic']);

    expect(analyticsEngagement()['compare']['answer'])->toBe('Only Reddit brought visitors in these days, so there is nothing to compare it with yet.');
});

it('lists the posts opened most of the period and the device, whatever source is chosen, a deleted post removed', function () {
    $glacier = Post::factory()->published()->create(['title' => 'Glacier Blue Water']);
    $foggy = Post::factory()->published()->create(['title' => 'Foggy Hilltop Town']);
    $gone = Post::factory()->published()->create(['title' => 'Coral Reef Dive']);
    $gone->delete();

    engagementVisitors('reddit:organic', 30, fn (int $n): array => ['post' => ['views' => 2, 'voted' => $n <= 3, 'commented' => $n === 4]], $glacier->id);
    $google = analyticsVisit('bob', ['source_key' => 'google:organic']);
    analyticsVisitPost($google, $foggy->id, ['views' => 7, 'voted' => true]);
    analyticsVisitPost($google, $gone->id, ['views' => 3]);
    // Never a post at all by now.
    analyticsVisitPost($google, 999999, ['views' => 1]);

    $content = analyticsEngagement()['content'];
    $reddit = analyticsEngagement(['source' => 'reddit:organic'])['content'];

    expect(array_map(fn (array $row): string => implode(' | ', [$row['title'], '#'.$row['id'], $row['views'], $row['viewers'], $row['votes'], $row['voters'], $row['comments'], $row['engagement'], $row['registrations']]), $content['rows']))->toBe([
        'Glacier Blue Water | #'.$glacier->id.' | 60 | 30 | 3 | 3 voters | 1 | 13.3% | 0',
        // Fewer viewers than a row needs: no engagement rate.
        'Foggy Hilltop Town | #'.$foggy->id.' | 7 | 1 | 1 | 1 voter | 0 | — | 0',
        'Post removed | #'.$gone->id.' | 3 | 1 | 0 | 0 voters | 0 | — | 0',
        'Post removed | #999999 | 1 | 1 | 0 | 0 voters | 0 | — | 0',
    ])
        ->and(array_column($content['rows'], 'url'))->toBe([route('posts.show', $glacier->id), route('posts.show', $foggy->id), null, null])
        ->and($content['rows'][0]['engagementTitle'])->toBe('4 of 30')
        ->and($content['note'])->toBe('Top posts by views · Last 30 days.')
        // The source chosen narrows the tiles and the funnel, never the posts.
        ->and($reddit['rows'])->toBe($content['rows'])
        ->and($reddit['note'])->toBe('Top posts by views · Last 30 days. Post-level numbers cover all sources; the source and campaign filters apply to the cards and funnel above.');

    // Nobody of the device: one state, no table.
    Livewire::withQueryParams(['tab' => 'engagement', 'device' => 'desktop'])->test(GrowthAnalyticsPage::class)->call('load')
        ->assertSee('No visitors for these filters');
});

it('credits an account to the first post its session opened: the feed, a post, another, a game, then signing up', function () {
    CarbonImmutable::setTestNow('2026-11-17 12:00:00');
    $draft = publishableGameDraft();
    liveGame($draft);
    allowGames();
    $first = Post::factory()->published()->create(['title' => 'Glacier Blue Water']);
    $second = Post::factory()->published()->create(['title' => 'Foggy Hilltop Town']);
    // A visitor, not the administrator: staff leave no unpaid visits.
    $this->app['auth']->forgetGuards();

    pageRuns('/');
    CarbonImmutable::setTestNow('2026-11-17 12:02:00');
    pageRuns('/posts/'.$first->id, ['Referer' => url('/')]);
    CarbonImmutable::setTestNow('2026-11-17 12:04:00');
    pageRuns('/posts/'.$second->id, ['Referer' => url('/posts/'.$first->id)]);
    CarbonImmutable::setTestNow('2026-11-17 12:05:00');
    $visitId = pageRuns('/games/'.$draft['game']->slug, ['Referer' => url('/')]);
    app(MarkTrafficVisitAction::class)->handle((int) $visitId, TrafficEvent::Started, CarbonImmutable::now());
    $member = User::factory()->create(['email_verified_at' => now()]);
    app(RecordAccountSignupAction::class)->handle($member, browserHashOf(OPEN_TOKEN), AccountSignupMethod::Email, AccountSignupPlace::Game, CarbonImmutable::now());
    app(ConfirmAccountAttributionAction::class)->handle($member->id, CarbonImmutable::now());

    // Someone who made an account without opening a post.
    analyticsVisit('cat', ['network_day' => '2026-11-17', 'account_at' => '2026-11-17 10:20:00']);
    CarbonImmutable::setTestNow('2026-11-18 12:00:00');
    $this->actingAs($this->admin);

    $credited = TrafficVisit::query()->whereKey($visitId)->sole();

    // The account is the game's visit's, the posts another visit's of the same session.
    expect($credited->account_at)->not->toBeNull()
        ->and(TrafficVisitPost::query()->pluck('visit_id')->unique()->all())->not->toContain($visitId)
        ->and(TrafficVisitPost::query()->pluck('visit_id')->unique()->all())->toContain($credited->entry_visit_id);

    $content = analyticsEngagement()['content'];

    expect(array_map(fn (array $row): string => $row['title'].' '.$row['registrations'], $content['rows']))->toBe(['Glacier Blue Water 1', 'Foggy Hilltop Town 0'])
        ->and(array_column($content['rows'], 'quiet'))->toBe([false, true])
        ->and($content['foot'])->toBe('Engagement: unique viewers who voted or commented ÷ unique viewers, “—” until the viewers reach 30. Votes and comments count the visits that voted on the post or commented on it. Registrations: accounts made in a session whose first post opened was this one. Not attributed: 1 account, whose session opened no post.');
});

it('shows only the accounts, saying so itself, while Record site visits is off', function () {
    analyticsVisit('ann', ['signup_at' => '2026-11-15 10:19:00', 'account_at' => '2026-11-15 10:20:00']);
    recordTraffic(site: false);

    $page = Livewire::withQueryParams(['tab' => 'engagement'])->test(GrowthAnalyticsPage::class)->call('load');
    $data = $page->viewData('data');

    expect(engagementCards($data))->toBe(['Accounts created | 1 | Accounts | No earlier data', 'Activated users | 0 | Accounts | No earlier data'])
        ->and($data['notice']['text'])->toBe(EngagementTab::SITE_OFF)
        ->and($data)->not->toHaveKeys(['funnel', 'compare', 'content'])
        // The tab says it; the page's own word would only repeat it, and another tab keeps it.
        ->and(array_column($page->viewData('notices'), 'text'))->not->toContain(AnalyticsView::SITE_OFF)
        ->and(array_column(Livewire::withQueryParams([])->test(GrowthAnalyticsPage::class)->call('load')->viewData('notices'), 'text'))->toContain(AnalyticsView::SITE_OFF);

    $page->assertSee(EngagementTab::SITE_OFF)
        ->assertSee('Open Tracking')
        ->assertDontSee('Website funnel')
        ->assertDontSee('Content engagement');

    // With nothing recorded at all the page's warning says so, and the tab adds nothing to it.
    recordTraffic(capture: false);
    $off = Livewire::withQueryParams(['tab' => 'engagement'])->test(GrowthAnalyticsPage::class)->call('load');

    expect($off->viewData('data')['notice'])->toBeNull()
        ->and($off->viewData('data')['siteOff'])->toBeTrue()
        ->and(substr_count($off->html(), 'Open Tracking'))->toBe(1);
});

it('says once that nobody matches the filters, and reads no visit until the page is in a browser', function () {
    analyticsVisitPost(analyticsVisit('ann', ['source_key' => 'reddit:organic']), 1);

    expect(analyticsEngagement(['source' => 'google:organic']))->toMatchArray(['empty' => true, 'filtered' => true, 'range' => '20 Oct — 18 Nov 2026']);

    Livewire::withQueryParams(['tab' => 'engagement', 'source' => 'google:organic'])->test(GrowthAnalyticsPage::class)->call('load')
        ->assertSee('No visitors for these filters')
        ->assertDontSee('Website funnel')
        ->assertDontSee('Compare sources');

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->get(GrowthAnalyticsPage::getUrl(['tab' => 'engagement', 'a' => 'reddit:organic']))->assertOk()
        ->assertSee('Site engagement')
        ->assertDontSee('Website funnel');

    expect(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $query): bool => str_contains($query, 'traffic_visit'))->values()->all())->toBe([]);
    DB::disableQueryLog();

    $this->actingAs(User::factory()->moderator()->create())->get(GrowthAnalyticsPage::getUrl(['tab' => 'engagement']))->assertForbidden();
});

it('reads the tab in a bounded number of queries, however many sources, posts and visitors there are', function () {
    $queries = function (array $query = []): int {
        $page = Livewire::withQueryParams(['tab' => 'engagement', ...$query])->test(GrowthAnalyticsPage::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $page->call('load');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    analyticsVisitPost(analyticsVisit('ann', ['source_key' => 'reddit:organic']), 1);
    $few = $queries();

    foreach (range(1, 12) as $source) {
        foreach (range(1, 3) as $n) {
            $visit = analyticsVisit("visitor-{$source}-{$n}", ['source_key' => 'host.site'.$source.'.com:referral', 'account_at' => $n === 1 ? '2026-11-15 10:20:00' : null]);
            analyticsSession($visit);
            analyticsVisitPost($visit, Post::factory()->published()->create()->id, ['views' => $n]);
        }
    }

    $many = $queries();
    $narrowed = $queries(['source' => 'host.site1.com:referral', 'a' => 'host.site2.com:referral', 'b' => 'host.site2.com:referral']);

    // The shell's filters and hints; the tiles now and before; the funnel; the two sources compared; the posts with their accounts: never one per row.
    expect($few)->toBeLessThanOrEqual(24)
        ->and($many)->toBeLessThanOrEqual(24)
        ->and($narrowed)->toBeLessThanOrEqual(24)
        ->and($many - $few)->toBeLessThanOrEqual(3);
});
