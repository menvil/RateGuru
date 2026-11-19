<?php

use App\Actions\Analytics\LogJourneyLookupAction;
use App\Enums\GameAudience;
use App\Exceptions\Traffic\CannotManageTrafficException;
use App\Filament\Pages\GrowthAnalyticsPage;
use App\Models\AccountBrowser;
use App\Models\GameAssignment;
use App\Models\GameAttempt;
use App\Models\GameVersion;
use App\Models\TrafficVisit;
use App\Models\User;
use App\Support\Analytics\JourneyTimeline;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

/*
 * Analytics → Journeys: a browser found by the first hex digits of its
 * visitor hash — the browsers sharing them listed to pick one — and a
 * member by `user_…`, with every browser it signed up or signed in with;
 * every step recorded, in order — the account's signed-in attempts in the
 * journey of the browser it signed up in too; Attribution for an account
 * and for nobody signed up yet; with nothing looked up, the first example's
 * journey, a site visitor's where nobody signed up or played; an email
 * address never read; a lookup written down only for an ID, once; nobody
 * but an administrator who may manage traffic sources. Overview's example
 * journey is one of its slice, and Geography's footnote leads here.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00'));
    // The figures as they are at each read: the read cache has tests of its own (AnalyticsReadCacheTest).
    config(['analytics.read_cache_minutes' => 0]);
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

/** The Journeys tab, read as the page reads it once it is in a browser. */
function analyticsJourneys(array $query = []): array
{
    return Livewire::withQueryParams(['tab' => 'journeys', ...$query])->test(GrowthAnalyticsPage::class)->call('load')->viewData('data');
}

/** A journey's steps as "time title". */
function journeySteps(array $data): array
{
    return array_map(fn (array $entry): string => $entry['time'].' '.$entry['title'], $data['journey']['entries']);
}

it('finds a browser by the first digits of its visitor hash, and lists the browsers that share them', function () {
    $prefix = '8f31c2ab';
    analyticsVisit('one')->forceFill(['visitor_hash' => $prefix.str_repeat('1', 56)])->save();
    analyticsVisit('two')->forceFill(['visitor_hash' => $prefix.str_repeat('2', 56)])->save();
    // The next digits up and the last hash of the range: one outside it, one inside.
    analyticsVisit('next')->forceFill(['visitor_hash' => '8f31c2ac'.str_repeat('0', 56)])->save();
    GameAttempt::factory()->create(['version_id' => GameVersion::factory()->create()->id, 'visitor_hash' => 'ffffffff'.str_repeat('f', 56)]);

    $matches = analyticsJourneys(['who' => 'visitor_8f31c2ab']);

    expect($matches['state'])->toBe('matches')
        ->and($matches['matches']['title'])->toBe('2 browsers have IDs beginning visitor_8f31c2ab')
        ->and($matches['matches']['rows'])->toBe(['visitor_8f31c2ab11111111', 'visitor_8f31c2ab22222222']);

    // One picked by more of its digits; a player with no visit, found by an attempt, in any case and around spaces.
    expect(analyticsJourneys(['who' => 'visitor_8f31c2ab22222222'])['journey']['label'])->toBe('visitor_8f31c2ab · not registered')
        ->and(analyticsJourneys(['who' => ' VISITOR_FFFFFFFF '])['journey']['label'])->toBe('visitor_ffffffff · not registered')
        ->and(analyticsJourneys(['who' => 'visitor_8f31c2ac'])['journey']['label'])->toBe('visitor_8f31c2ac · not registered');

    Livewire::withQueryParams(['tab' => 'journeys', 'who' => 'visitor_8f31c2ab'])->test(GrowthAnalyticsPage::class)->call('load')
        ->assertSee('visitor_8f31c2ab22222222')
        ->call('pickJourney', 'visitor_8f31c2ab22222222')
        ->assertSet('who', 'visitor_8f31c2ab22222222')
        ->assertViewHas('data', fn (array $data): bool => $data['state'] === 'journey');
});

it('says so for an ID nobody has, for anything that is no ID, and when no journey was recorded at all', function () {
    expect(analyticsJourneys())->toMatchArray(['state' => 'none', 'examples' => []]);

    Livewire::withQueryParams(['tab' => 'journeys'])->test(GrowthAnalyticsPage::class)->call('load')
        ->assertSee('No journeys yet')
        ->assertSee('Admins only · lookups are logged');

    analyticsVisit('ann');

    // A visit of the site is a journey too: with nobody signed up or playing, its browser is the example shown.
    expect(analyticsJourneys())->toMatchArray(['state' => 'journey', 'examples' => [['id' => JourneyTimeline::visitorId(hash('sha256', 'ann')), 'on' => true]]])
        ->and(analyticsJourneys(['who' => 'visitor_00000000'])['missing'])->toBe('No first-party journey for “visitor_00000000”. IDs are opaque; names and emails can’t be searched here.')
        ->and(analyticsJourneys(['who' => 'user_999999'])['missing'])->toBe('No first-party journey for “user_999999”. IDs are opaque; names and emails can’t be searched here.')
        ->and(analyticsJourneys(['who' => 'Ann@Example.com'])['missing'])->toBe('“ann@example.com” isn’t a visitor_… or user_… ID. IDs are opaque; names and emails can’t be searched here.')
        // Staff and Personas have no journey here.
        ->and(analyticsJourneys(['who' => 'user_'.$this->admin->id])['state'])->toBe('missing')
        ->and(analyticsJourneys(['who' => 'user_'.User::factory()->persona()->create()->id])['state'])->toBe('missing');
});

it('tells every step of a member’s way, in order, with what the sign-up is credited to', function () {
    $member = journeyAccount(CarbonImmutable::parse('2026-11-08 00:00:00'));
    $data = analyticsJourneys(['who' => 'user_'.$member['user']->id]);
    $journey = $data['journey'];
    $click = $member['click'];

    expect($journey['label'])->toBe(JourneyTimeline::visitorId($member['hash']).' · user_'.$member['user']->id)
        ->and($journey['status'])->toBe('Registered and activated · 2 sessions · 2 attempts')
        ->and(journeySteps($data))->toBe([
            '08 Nov 19:42 Arrived from Reddit',
            '08 Nov 19:43 Started Trust Your Taste',
            '08 Nov 19:44 Completed the game',
            '10 Nov 18:10 Returned from ExoClick tests',
            '10 Nov 18:10 Assigned to Trust Your Taste',
            '10 Nov 18:11 Started Trust Your Taste',
            '10 Nov 18:12 Completed the game',
            '10 Nov 18:12 Reached the goal',
            '10 Nov 18:12 Clicked “Sign up”',
            '10 Nov 18:13 Signed up with Google',
            '10 Nov 18:13 Result claimed',
            '10 Nov 18:13 Confirmed the account',
            '10 Nov 18:20 Opened 2 posts',
            '13 Nov 10:00 Came back on day 3 and voted — activation rule met',
            '15 Nov 09:00 Came back on day 5',
        ]);

    $entries = collect($journey['entries'])->keyBy('title');

    expect($entries['Arrived from Reddit']['body'])->toBe('No campaign tags. Source inferred from the referrer reddit.com.')
        ->and($entries['Arrived from Reddit']['meta'])->toBe('session_'.$member['first']->id.' · /games/trust-your-taste · Mobile · Hamburg, Germany')
        ->and($entries['Returned from ExoClick tests']['body'])->toBe('Paid click · Versus October Launch · video_03. The Reddit first touch is kept.')
        ->and($entries['Returned from ExoClick tests']['meta'])->toBe('session_'.$click->id.' · /games/trust-your-taste · Desktop · Berlin, Germany')
        ->and($entries['Assigned to Trust Your Taste']['body'])->toBe('Assigned V7 in the period from 07 Nov (V7 80% · V8 20%). The version stays the same when they come back.')
        ->and($entries['Opened 2 posts']['body'])->toBe('3 views · voted on 1 post.')
        ->and($entries['Opened 2 posts']['meta'])->toBe('session_'.$click->id.' · #12, #15')
        ->and($entries['Signed up with Google']['body'])->toBe('Account user_'.$member['user']->id.' created in the game. Conversion touch: ExoClick tests.')
        ->and($entries['Result claimed']['body'])->toBe('attempt_'.$member['attempt']->id.' is linked to the new account, user_'.$member['user']->id.'.')
        ->and($entries['Came back on day 5']['body'])->toBe('Commented.')
        ->and(collect($journey['touches'])->map(fn (array $touch): string => $touch['label'].': '.$touch['value'])->all())->toBe([
            'First touch: Reddit · organic_social',
            'Conversion touch: ExoClick tests · paid_ads',
            'Last non-direct touch: ExoClick tests · paid_ads',
            'Registration credited to: Trust Your Taste · V7',
        ])
        ->and($journey['touches'][0]['note'])->toBe('08 Nov · inferred from referrer reddit.com, no tags')
        ->and($journey['touches'][1]['note'])->toBe('10 Nov · Versus October Launch · video_03')
        // The place is the latest visit's.
        ->and(collect($journey['identity'])->pluck('value', 'label')->all())->toBe([
            'Visitor ID' => JourneyTimeline::visitorId($member['hash']),
            'User ID' => 'user_'.$member['user']->id,
            'Sessions · attempts' => '2 · 2',
            'Location' => 'Berlin, Germany · from IP',
        ]);

    // The browser that signed up is the member's too, with every step of the account.
    expect(journeySteps(analyticsJourneys(['who' => JourneyTimeline::visitorId($member['hash'])])))->toBe(journeySteps($data));
});

it('shows the attempts and versions an account had signed in, in the journey of the browser it signed up in too', function () {
    $member = journeyAccount(CarbonImmutable::parse('2026-11-08 00:00:00'));
    $anonymous = GameAssignment::query()->findOrFail($member['attempt']->assignment_id);
    // Signed in, a version and an attempt are the account's alone, with no browser named.
    $assignment = GameAssignment::query()->forceCreate(['game_id' => $member['game']->id, 'audience' => GameAudience::Authenticated, 'user_id' => $member['user']->id, 'version_id' => $anonymous->version_id,
        'period_id' => $anonymous->period_id, 'assigned_at' => '2026-11-17 10:00:00', 'last_seen_at' => '2026-11-17 10:00:00', 'ended_at' => '2026-11-17 12:00:00']);
    GameAttempt::factory()->completed()->create(['version_id' => $anonymous->version_id, 'assignment_id' => $assignment->id, 'user_id' => $member['user']->id, 'visitor_hash' => null,
        'started_at' => '2026-11-17 10:01:00', 'completed_at' => '2026-11-17 10:02:00', 'last_activity_at' => '2026-11-17 10:02:00']);

    $data = analyticsJourneys(['who' => 'user_'.$member['user']->id]);
    $browser = analyticsJourneys(['who' => JourneyTimeline::visitorId($member['hash'])]);

    expect(array_slice(journeySteps($data), -3))->toBe(['17 Nov 10:00 Assigned to Trust Your Taste', '17 Nov 10:01 Started Trust Your Taste', '17 Nov 10:02 Completed the game'])
        ->and($data['journey']['status'])->toBe('Registered and activated · 2 sessions · 3 attempts')
        ->and(journeySteps($browser))->toBe(journeySteps($data))
        ->and($browser['journey']['status'])->toBe($data['journey']['status'])
        // Only the version still kept is said to stay.
        ->and(collect($data['journey']['entries'])->where('title', 'Assigned to Trust Your Taste')->pluck('body')->values()->all())->toBe([
            'Assigned V7 in the period from 07 Nov (V7 80% · V8 20%). The version stays the same when they come back.',
            'Assigned V7 in the period from 07 Nov (V7 80% · V8 20%). Kept until 17 Nov 12:00, when the version lost its share or could no longer be played.',
        ]);
});

it('joins every browser a member signed up or signed in with, and keeps the account’s steps to its own journey', function () {
    $member = journeyAccount(CarbonImmutable::parse('2026-11-08 00:00:00'));
    $phone = analyticsVisit('journey-phone', ['network_day' => '2026-11-16', 'arrived_at' => '2026-11-16 10:00:00', 'device' => 'mobile', 'login_at' => '2026-11-16 10:01:00']);
    $phone->forceFill(['entry_visit_id' => $phone->id])->save();
    AccountBrowser::factory()->create(['user_id' => $member['user']->id, 'visitor_hash' => $phone->visitor_hash, 'linked_at' => '2026-11-16 10:01:00']);
    $phoneId = JourneyTimeline::visitorId($phone->visitor_hash);

    $data = analyticsJourneys(['who' => 'user_'.$member['user']->id]);

    expect($data['journey']['label'])->toBe('user_'.$member['user']->id.' · 2 browsers')
        ->and($data['journey']['status'])->toBe('Registered and activated · 3 sessions · 2 attempts')
        ->and(array_slice(journeySteps($data), -2))->toBe(['16 Nov 10:00 Returned with no attribution', '16 Nov 10:01 Signed in'])
        // A visit says whose browser it was.
        ->and(collect($data['journey']['entries'])->firstWhere('title', 'Returned with no attribution')['meta'])->toBe('session_'.$phone->id.' · '.$phoneId.' · Mobile')
        ->and(collect($data['journey']['identity'])->firstWhere('label', 'Browsers')['value'])->toBe(JourneyTimeline::visitorId($member['hash']).', '.$phoneId)
        ->and($data['journey']['note'])->toStartWith('Every browser user_'.$member['user']->id.' signed up or signed in with, together');

    // The phone alone only signed in: the account's own steps are its journey's.
    $alone = analyticsJourneys(['who' => $phoneId]);

    expect($alone['journey']['status'])->toBe('Signed in as user_'.$member['user']->id.' · 1 session · 0 attempts')
        ->and(journeySteps($alone))->toBe(['16 Nov 10:00 Arrived with no attribution', '16 Nov 10:01 Signed in'])
        ->and($alone['journey']['touches'][1])->toBe(['label' => 'Conversion touch', 'value' => 'In another browser', 'note' => 'user_'.$member['user']->id.' signed up elsewhere: its journey has the sign-up']);
});

it('names a member’s last non-direct touch even when it came before the latest visits read', function () {
    $reddit = analyticsVisit('long-member', ['source_key' => 'reddit:organic', 'network_day' => '2026-11-01', 'arrived_at' => '2026-11-01 10:00:00']);
    $reddit->forceFill(['entry_visit_id' => $reddit->id])->save();
    $member = retentionAccount('2026-11-01 10:05:00', [], ['visitor_hash' => $reddit->visitor_hash]);

    foreach (range(1, JourneyTimeline::MOST_STEPS + 1) as $n) {
        analyticsVisit('long-member', ['network_day' => '2026-11-02', 'arrived_at' => CarbonImmutable::parse('2026-11-02 00:00:00')->addMinutes($n * 5)]);
    }

    $journey = analyticsJourneys(['who' => 'user_'.$member->user_id])['journey'];

    expect($journey['cut'])->toBeTrue()
        ->and(collect($journey['touches'])->firstWhere('label', 'Last non-direct touch')['value'])->toBe('Reddit · organic_social');
});

it('says of an anonymous player where they first and last came from, and that nothing converted yet', function () {
    $player = journeyPlayer(CarbonImmutable::parse('2026-11-05 12:03:00'));
    $data = analyticsJourneys(['who' => JourneyTimeline::visitorId($player['hash'])]);

    expect($data['journey']['label'])->toBe(JourneyTimeline::visitorId($player['hash']).' · not registered')
        ->and($data['journey']['status'])->toBe('Anonymous · email captured · 2 sessions · 2 attempts')
        ->and(journeySteps($data))->toBe([
            '05 Nov 12:03 Arrived with no attribution',
            '05 Nov 12:04 Started Swipe Challenge',
            '05 Nov 12:05 Didn’t finish',
            '08 Nov 12:03 Returned from a shared result',
            '08 Nov 12:04 Started Swipe Challenge',
            '08 Nov 12:05 Completed the game',
            '08 Nov 12:06 Email captured',
            '08 Nov 12:07 Shared the result',
        ])
        ->and($data['journey']['touches'])->toBe([
            ['label' => 'First touch', 'value' => 'Direct / Unknown', 'note' => '05 Nov · no tags and no referrer, so no source is assumed'],
            ['label' => 'Latest touch', 'value' => 'Shared results · referral', 'note' => '08 Nov · a result another player shared'],
            ['label' => 'Conversion touch', 'value' => 'None yet', 'note' => 'No account created'],
        ]);
});

it('shows the first example’s journey while nothing is looked up, the latest accounts first and then anonymous players', function () {
    $older = journeyAccount(CarbonImmutable::parse('2026-11-01 00:00:00'), 'older-member');
    $member = journeyAccount(CarbonImmutable::parse('2026-11-08 00:00:00'));
    $player = journeyPlayer(CarbonImmutable::parse('2026-11-05 12:03:00'));
    // Not confirmed: no example.
    retentionAccount('2026-11-17 10:00:00', [], ['confirmed_at' => null]);

    $data = analyticsJourneys();

    expect(array_column($data['examples'], 'id'))->toBe(['user_'.$member['user']->id, 'user_'.$older['user']->id, JourneyTimeline::visitorId($player['hash'])])
        ->and(array_column($data['examples'], 'on'))->toBe([true, false, false])
        ->and($data['state'])->toBe('journey')
        ->and($data['journey']['label'])->toBe(JourneyTimeline::visitorId($member['hash']).' · user_'.$member['user']->id);

    // An example picked is looked up, and shown pressed.
    Livewire::withQueryParams(['tab' => 'journeys'])->test(GrowthAnalyticsPage::class)->call('load')
        ->call('pickJourney', JourneyTimeline::visitorId($player['hash']))
        ->assertSet('who', JourneyTimeline::visitorId($player['hash']))
        ->assertViewHas('data', fn (array $data): bool => array_column($data['examples'], 'on') === [false, false, true] && $data['journey']['status'] === 'Anonymous · email captured · 2 sessions · 2 attempts');
});

it('never reads an email address a player left, nor a member’s', function () {
    $player = journeyPlayer(CarbonImmutable::parse('2026-11-05 12:03:00'), email: 'secret.player@example.com');
    $member = journeyAccount(CarbonImmutable::parse('2026-11-08 00:00:00'));
    $member['user']->forceFill(['email' => 'secret.member@example.com'])->save();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $playerPage = Livewire::withQueryParams(['tab' => 'journeys', 'who' => JourneyTimeline::visitorId($player['hash'])])->test(GrowthAnalyticsPage::class)->call('load');
    $memberPage = Livewire::withQueryParams(['tab' => 'journeys', 'who' => 'user_'.$member['user']->id])->test(GrowthAnalyticsPage::class)->call('load');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    $playerPage->assertSee('Email captured')->assertDontSee('secret.player@example.com');
    $memberPage->assertSee('Signed up with Google')->assertDontSee('secret.member@example.com');

    $reads = function (string $table) use ($queries): array {
        return collect($queries)
            ->filter(fn (array $query): bool => preg_match('/\bfrom\s+[`"]?'.$table.'[`"]?/i', $query['query']) === 1)
            ->map(fn (array $query): string => preg_match('/^\s*select\s+(.*?)\s+from\s/is', $query['query'], $match) === 1 ? $match[1] : '')
            ->values()->all();
    };

    $captures = $reads('game_email_captures');

    expect($captures)->not->toBeEmpty()
        ->and(collect($captures)->filter(fn (string $columns): bool => str_contains($columns, '*') || preg_match('/\bemail\b/', $columns) === 1)->all())->toBe([]);

    // Only the member's id is read of their row.
    $memberReads = collect($queries)
        ->filter(fn (array $query): bool => preg_match('/\bfrom\s+[`"]?users[`"]?/i', $query['query']) === 1 && in_array($member['user']->id, $query['bindings'], false))
        ->map(fn (array $query): string => $query['query'])->values()->all();

    expect($memberReads)->not->toBeEmpty()
        ->and(collect($memberReads)->filter(fn (string $query): bool => preg_match('/^\s*select\s+\*|\bemail\b/i', $query) === 1)->all())->toBe([]);
});

it('writes a lookup down once an ID is typed, picked or opened, and never on the way to one', function () {
    $member = journeyAccount(CarbonImmutable::parse('2026-11-08 00:00:00'));
    $memberId = 'user_'.$member['user']->id;
    $browserId = JourneyTimeline::visitorId($member['hash']);
    Log::spy();

    $logged = fn (?string $journey = null, ?string $via = null) => Log::shouldHaveReceived('info')->withArgs(fn (string $event, array $context): bool => $event === 'analytics.journey.lookup'
        && ($journey === null || $context['journey'] === $journey) && ($via === null || $context['via'] === $via) && $context['admin_id'] === $this->admin->id);

    // The tab with nothing looked up shows the first example: nobody asked for it.
    $page = Livewire::withQueryParams(['tab' => 'journeys'])->test(GrowthAnalyticsPage::class)->call('load');
    $none = fn () => Log::shouldNotHaveReceived('info', fn (string $event): bool => $event === 'analytics.journey.lookup');
    $none();

    // Typed: nothing on the way to an ID, nor for what is no ID.
    $page->set('who', 'visitor_')->set('who', substr($browserId, 0, -2))->set('who', 'ann@example.com');
    $none();

    $page->set('who', strtoupper($browserId));
    $logged($browserId, 'search')->once();

    $page->call('pickJourney', $memberId);
    $logged($memberId, 'pick')->once();

    // Opened from an address, as the page comes into a browser.
    Livewire::withQueryParams(['tab' => 'journeys', 'who' => $memberId])->test(GrowthAnalyticsPage::class)->call('load');
    $logged($memberId, 'address')->once();

    // Another tab's address has no journey to look up.
    Livewire::withQueryParams(['tab' => 'overview', 'who' => $memberId])->test(GrowthAnalyticsPage::class)->call('load')->assertSet('who', '');
    $logged()->times(3);
});

it('answers nobody but an administrator who may manage traffic sources', function () {
    $member = journeyAccount(CarbonImmutable::parse('2026-11-08 00:00:00'));
    $moderator = User::factory()->moderator()->create();

    $this->get(GrowthAnalyticsPage::getUrl(['tab' => 'journeys', 'who' => 'user_'.$member['user']->id]))->assertOk()->assertDontSee('Came back on day 3');
    $this->actingAs($moderator)->get(GrowthAnalyticsPage::getUrl(['tab' => 'journeys', 'who' => 'user_'.$member['user']->id]))->assertForbidden();
    $this->actingAs(User::factory()->admin()->limited()->create())->get(GrowthAnalyticsPage::getUrl(['tab' => 'journeys']))->assertForbidden();

    $this->actingAs($this->admin);
    $page = Livewire::withQueryParams(['tab' => 'journeys'])->test(GrowthAnalyticsPage::class)->call('load');
    $this->actingAs($moderator);

    $page->call('pickJourney', 'user_'.$member['user']->id)->assertForbidden();

    expect(fn () => app(LogJourneyLookupAction::class)->handle($moderator, 'user_'.$member['user']->id, 'pick'))->toThrow(CannotManageTrafficException::class);
});

it('leads from Overview to the journey of one of its slice, and from Geography’s footnote to the tab', function () {
    $reddit = analyticsVisit('reddit-member', ['source_key' => 'reddit:organic', 'network_day' => '2026-11-10', 'arrived_at' => '2026-11-10 10:00:00']);
    $google = analyticsVisit('google-member', ['source_key' => 'google:organic', 'network_day' => '2026-11-12', 'arrived_at' => '2026-11-12 10:00:00']);
    $redditMember = retentionAccount('2026-11-10 10:05:00', [], ['credited_visit_id' => $reddit->id]);
    $googleMember = retentionAccount('2026-11-12 10:05:00', [], ['credited_visit_id' => $google->id]);
    $bing = analyticsVisit('bing-player', ['source_key' => 'bing:organic', 'started_at' => '2026-11-15 10:01:00']);
    analyticsVisit('yesterday-visitor', ['network_day' => '2026-11-17', 'arrived_at' => '2026-11-17 10:00:00']);

    $follow = fn (array $query) => Livewire::withQueryParams($query)->test(GrowthAnalyticsPage::class)->call('load')
        ->assertSee('See an example journey')
        ->call('openExampleJourney')
        ->assertSet('tab', 'journeys');

    // The latest account of the slice, read only as the way is followed, and looked up.
    Log::spy();
    $follow([])->assertSet('who', 'user_'.$googleMember->user_id);
    Log::shouldHaveReceived('info')->withArgs(fn (string $event, array $context): bool => $event === 'analytics.journey.lookup' && $context['journey'] === 'user_'.$googleMember->user_id && $context['via'] === 'pick')->once();

    $follow(['source' => 'reddit:organic'])->assertSet('who', 'user_'.$redditMember->user_id)
        ->assertViewHas('data', fn (array $data): bool => $data['journey']['label'] === JourneyTimeline::visitorId((string) $redditMember->visitor_hash).' · user_'.$redditMember->user_id);
    // No account in the slice: a player of it.
    $follow(['source' => 'bing:organic'])->assertSet('who', JourneyTimeline::visitorId($bing->visitor_hash));
    // Nobody of the slice signed up or played: the first example.
    $follow(['period' => 'yesterday'])->assertSet('who', '')
        ->assertViewHas('data', fn (array $data): bool => $data['journey']['label'] === JourneyTimeline::visitorId((string) $googleMember->visitor_hash).' · user_'.$googleMember->user_id);

    // Only Overview's funnel offers it.
    Livewire::withQueryParams(['tab' => 'geo'])->test(GrowthAnalyticsPage::class)->call('load')->call('openExampleJourney')->assertSet('tab', 'geo');

    Livewire::withQueryParams(['tab' => 'geo'])->test(GrowthAnalyticsPage::class)->call('load')
        ->assertSee('A visitor’s location for each visit is')
        ->assertSee('on the Journeys tab')
        ->call('$set', 'tab', 'journeys')
        ->assertSet('tab', 'journeys');
});

it('reads a journey in a bounded number of queries, however long it is', function () {
    $member = journeyAccount(CarbonImmutable::parse('2026-11-08 00:00:00'));
    $queries = function () use ($member): int {
        $page = Livewire::withQueryParams(['tab' => 'journeys', 'who' => 'user_'.$member['user']->id])->test(GrowthAnalyticsPage::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $page->call('load');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $few = $queries();

    foreach (range(1, 30) as $n) {
        $visit = analyticsVisit('journey-member', ['network_day' => '2026-11-16', 'arrived_at' => CarbonImmutable::parse('2026-11-16 08:00:00')->addMinutes($n * 40)]);
        analyticsVisitPost($visit, 100 + $n);
        GameAttempt::factory()->completed()->create(['version_id' => $member['attempt']->version_id, 'visitor_hash' => $member['hash'], 'started_at' => $visit->arrived_at]);
    }

    // The examples, the member and their browsers, then each kind of step once — never one per visit or attempt.
    expect(TrafficVisit::query()->where('visitor_hash', $member['hash'])->count())->toBe(32)
        ->and($queries())->toBe($few)
        ->and($few)->toBeLessThanOrEqual(30);
});
