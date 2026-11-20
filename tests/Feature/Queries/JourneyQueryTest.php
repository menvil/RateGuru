<?php

use App\Models\AccountAttribution;
use App\Models\AccountBrowser;
use App\Models\GameAttempt;
use App\Models\GameVersion;
use App\Models\TrafficVisitPost;
use App\Models\User;
use App\Queries\Analytics\JourneyQuery;
use App\Support\Analytics\JourneyTimeline;
use Carbon\CarbonImmutable;

/*
 * JourneyQuery on every engine: browsers found by a range of visitor
 * hashes — both ends inside, the digits either side outside, each browser
 * once whatever table it is in, a few more than are listed at most; the
 * account a browser signed up or only signed in with; a member's browsers,
 * the latest as many as a journey names; the examples, of every visit or of
 * a filter's, and the latest visitors where nobody signed up or played; a
 * long journey read as its latest steps, with its very first visit and the
 * visit its sign-up is credited to; and the latest rows of the posts its
 * sessions opened.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00'));
    $this->journeys = app(JourneyQuery::class);
});

it('finds the browsers whose hash begins with the digits, both ends of the range in and the digits either side out', function () {
    $version = GameVersion::factory()->create();
    analyticsVisit('low')->forceFill(['visitor_hash' => '8f31c2ab'.str_repeat('0', 56)])->save();
    // In the visits and the attempts both: one browser.
    analyticsVisit('high')->forceFill(['visitor_hash' => '8f31c2ab'.str_repeat('f', 56)])->save();
    GameAttempt::factory()->create(['version_id' => $version->id, 'visitor_hash' => '8f31c2ab'.str_repeat('f', 56)]);
    analyticsVisit('below')->forceFill(['visitor_hash' => '8f31c2aa'.str_repeat('f', 56)])->save();
    analyticsVisit('above')->forceFill(['visitor_hash' => '8f31c2ac'.str_repeat('0', 56)])->save();

    expect($this->journeys->find(['kind' => 'visitor', 'value' => '8f31c2ab']))->toBe([
        'browsers' => [], 'user' => null, 'account' => false, 'more' => false, 'matches' => ['8f31c2ab'.str_repeat('0', 56), '8f31c2ab'.str_repeat('f', 56)],
    ])
        ->and($this->journeys->find(['kind' => 'visitor', 'value' => '8f31c2abf'])['browsers'])->toBe(['8f31c2ab'.str_repeat('f', 56)])
        ->and($this->journeys->find(['kind' => 'visitor', 'value' => '00000000']))->toBeNull();

    // A few more than are listed, and no more.
    foreach (range(1, JourneyTimeline::MOST_MATCHES + 5) as $n) {
        GameAttempt::factory()->create(['version_id' => $version->id, 'visitor_hash' => 'abcdef01'.str_pad((string) $n, 56, '0', STR_PAD_LEFT)]);
    }

    expect($this->journeys->find(['kind' => 'visitor', 'value' => 'abcdef01'])['matches'])->toHaveCount(JourneyTimeline::MOST_MATCHES + 1);
});

it('names the account a browser signed up with, or only signed in to, and a member by every browser they used', function () {
    $member = User::factory()->create();
    $signedUp = analyticsVisit('signed-up');
    $signedIn = analyticsVisit('signed-in');
    retentionAccount('2026-11-10 10:00:00', [], ['visitor_hash' => $signedUp->visitor_hash], $member);
    AccountBrowser::factory()->create(['user_id' => $member->id, 'visitor_hash' => $signedUp->visitor_hash, 'linked_at' => '2026-11-10 10:00:00']);
    AccountBrowser::factory()->create(['user_id' => $member->id, 'visitor_hash' => $signedIn->visitor_hash, 'linked_at' => '2026-11-12 10:00:00']);
    $visitor = fn (string $hash): ?array => $this->journeys->find(['kind' => 'visitor', 'value' => substr($hash, 0, 8)]);

    expect($visitor($signedUp->visitor_hash))->toBe(['browsers' => [$signedUp->visitor_hash], 'user' => $member->id, 'account' => true, 'more' => false, 'matches' => []])
        ->and($visitor($signedIn->visitor_hash))->toBe(['browsers' => [$signedIn->visitor_hash], 'user' => $member->id, 'account' => false, 'more' => false, 'matches' => []])
        ->and($visitor(analyticsVisit('stranger')->visitor_hash))->toMatchArray(['user' => null, 'account' => false])
        ->and($this->journeys->find(['kind' => 'user', 'value' => $member->id]))->toBe(['browsers' => [$signedUp->visitor_hash, $signedIn->visitor_hash], 'user' => $member->id, 'account' => true, 'more' => false, 'matches' => []])
        ->and($this->journeys->find(['kind' => 'user', 'value' => User::factory()->moderator()->create()->id]))->toBeNull()
        ->and($this->journeys->find(['kind' => 'user', 'value' => User::factory()->persona()->create()->id]))->toBeNull();
});

it('reads a member’s latest browsers, as many as a journey names, with the one they signed up in, and says there were more', function () {
    $member = User::factory()->create();
    $signedUp = hash('sha256', 'signed-up');
    retentionAccount('2026-11-10 10:00:00', [], ['visitor_hash' => $signedUp], $member);
    $linked = CarbonImmutable::parse('2026-01-01 00:00:00');

    foreach (array_chunk(range(1, JourneyTimeline::MOST_BROWSERS + 1), 200) as $chunk) {
        AccountBrowser::query()->insert(array_map(fn (int $n): array => [
            'user_id' => $member->id, 'visitor_hash' => hash('sha256', 'browser-'.$n), 'linked_at' => $linked->addMinutes($n)->toDateTimeString(),
        ], $chunk));
    }

    $found = $this->journeys->find(['kind' => 'user', 'value' => $member->id]);

    // The earliest linked is the one left out; the rest oldest first, then the browser it signed up in.
    expect($found['more'])->toBeTrue()
        ->and($found['browsers'])->toHaveCount(JourneyTimeline::MOST_BROWSERS + 1)
        ->and($found['browsers'][0])->toBe(hash('sha256', 'browser-2'))
        ->and($found['browsers'][JourneyTimeline::MOST_BROWSERS - 1])->toBe(hash('sha256', 'browser-'.(JourneyTimeline::MOST_BROWSERS + 1)))
        ->and($found['browsers'][JourneyTimeline::MOST_BROWSERS])->toBe($signedUp)
        ->and($found['browsers'])->not->toContain(hash('sha256', 'browser-1'));
});

it('offers the latest three confirmed members’ accounts and then the latest two players no account has, of a filter’s visits when one is given', function () {
    $version = GameVersion::factory()->create();
    $reddit = analyticsVisit('reddit', ['source_key' => 'reddit:organic']);
    $accounts = collect(range(1, 4))->map(fn (int $n): AccountAttribution => retentionAccount('2026-11-1'.$n.' 10:00:00', [], ['credited_visit_id' => $n === 1 ? $reddit->id : null]));
    retentionAccount('2026-11-16 10:00:00', [], ['confirmed_at' => null]);
    retentionAccount('2026-11-17 10:00:00', [], [], User::factory()->persona()->create());

    $players = collect(['ann', 'bob', 'bob', 'cat'])->map(fn (string $name): GameAttempt => GameAttempt::factory()->create(['version_id' => $version->id, 'visitor_hash' => hash('sha256', $name)]));
    // Cat's browser signed in to an account: no anonymous player.
    AccountBrowser::factory()->create(['visitor_hash' => hash('sha256', 'cat')]);

    expect($this->journeys->examples())->toBe([
        'user_'.$accounts[3]->user_id, 'user_'.$accounts[2]->user_id, 'user_'.$accounts[1]->user_id,
        JourneyTimeline::visitorId(hash('sha256', 'bob')), JourneyTimeline::visitorId(hash('sha256', 'ann')),
    ]);

    analyticsVisit('dan', ['source_key' => 'reddit:organic', 'started_at' => '2026-11-15 10:01:00']);
    analyticsVisit('eve', ['source_key' => 'google:organic', 'started_at' => '2026-11-15 10:01:00']);

    expect($this->journeys->examples(analyticsFilter('30d', source: 'reddit:organic')))->toBe(['user_'.$accounts[0]->user_id, JourneyTimeline::visitorId(hash('sha256', 'dan'))])
        ->and($this->journeys->examples(analyticsFilter('yesterday')))->toBe([]);
});

it('offers the latest browsers that visited the site while nobody signed up or played, and only then', function () {
    analyticsVisit('ann', ['arrived_at' => '2026-11-15 09:00:00']);
    analyticsVisit('bob', ['arrived_at' => '2026-11-15 10:00:00']);
    analyticsVisit('bob', ['arrived_at' => '2026-11-15 11:00:00']);

    expect($this->journeys->examples())->toBe([JourneyTimeline::visitorId(hash('sha256', 'bob')), JourneyTimeline::visitorId(hash('sha256', 'ann'))])
        // A filter's examples stay its accounts and players.
        ->and($this->journeys->examples(analyticsFilter('30d')))->toBe([]);

    GameAttempt::factory()->create(['version_id' => GameVersion::factory()->create()->id, 'visitor_hash' => hash('sha256', 'cat')]);

    expect($this->journeys->examples())->toBe([JourneyTimeline::visitorId(hash('sha256', 'cat'))]);
});

it('reads a long journey as its latest steps, with its very first visit and the visit its sign-up is credited to', function () {
    $member = User::factory()->create();
    $elsewhere = analyticsVisit('another-browser', ['source_key' => 'google:organic']);
    retentionAccount('2026-11-10 10:00:00', [], ['credited_visit_id' => $elsewhere->id], $member);

    foreach (range(0, JourneyTimeline::MOST_STEPS) as $n) {
        analyticsVisit('long-journey', ['arrived_at' => CarbonImmutable::parse('2026-11-01 00:00:00')->addMinutes($n * 10), 'network_day' => '2026-11-01']);
    }

    $journey = $this->journeys->journey([hash('sha256', 'long-journey')], $member->id, $member->id);

    expect($journey['visits'])->toHaveCount(JourneyTimeline::MOST_STEPS)
        ->and($journey['cut'])->toBeTrue()
        ->and($journey['first']['at']->toDateTimeString())->toBe('2026-11-01 00:00:00')
        ->and($journey['visits'][JourneyTimeline::MOST_STEPS - 1]['at']->toDateTimeString())->toBe('2026-11-01 00:10:00')
        ->and(array_column($journey['credited'], 'id'))->toBe([$elsewhere->id])
        ->and($journey['credited'][0]['source'])->toBe('google:organic')
        ->and($journey['attribution']['visit'])->toBe($elsewhere->id);
});

it('reads the attempt a sign-up is credited to, with its game and version, though it is older than the latest attempts', function () {
    $member = User::factory()->create();
    $credited = GameVersion::factory()->create(['number' => 7]);
    $attempt = GameAttempt::factory()->completed()->create(['version_id' => $credited->id, 'user_id' => $member->id, 'started_at' => '2026-11-01 10:00:00', 'completed_at' => '2026-11-01 10:01:00', 'last_activity_at' => '2026-11-01 10:01:00']);
    retentionAccount('2026-11-01 10:05:00', [], ['credited_attempt_id' => $attempt->id], $member);

    // As many later attempts as a journey reads, of another game.
    GameAttempt::factory()->count(JourneyTimeline::MOST_STEPS)
        ->sequence(fn ($sequence): array => ['started_at' => CarbonImmutable::parse('2026-11-02 00:00:00')->addMinutes($sequence->index), 'last_activity_at' => CarbonImmutable::parse('2026-11-02 00:00:00')->addMinutes($sequence->index)])
        ->create(['version_id' => GameVersion::factory()->create()->id, 'user_id' => $member->id]);

    $journey = $this->journeys->journey([], $member->id, $member->id);

    expect($journey['cut'])->toBeTrue()
        ->and(array_column($journey['attempts'], 'id'))->not->toContain($attempt->id)
        ->and($journey['creditedAttempt'])->toMatchArray(['id' => $attempt->id, 'game' => $credited->game_id, 'version' => $credited->id])
        ->and($journey['versions'][$credited->id]['number'])->toBe(7)
        ->and($journey['games'])->toHaveKey($credited->game_id);

    // Among the attempts read, it needs no reading of its own.
    GameAttempt::query()->whereKeyNot($attempt->id)->delete();
    $journey = $this->journeys->journey([], $member->id, $member->id);

    expect(array_column($journey['attempts'], 'id'))->toBe([$attempt->id])
        ->and($journey['creditedAttempt'])->toBeNull();
});

it('reads when a visit’s member first voted, commented or made a post, and nothing for a visit they did nothing on', function () {
    analyticsVisit('active-reader', ['network_day' => '2026-11-10', 'arrived_at' => '2026-11-10 10:00:00']);
    analyticsVisit('active-reader', ['network_day' => '2026-11-15', 'arrived_at' => '2026-11-15 10:00:00', 'votes' => 2, 'acted_at' => '2026-11-15 10:04:00']);

    $visits = $this->journeys->journey([hash('sha256', 'active-reader')], null, null)['visits'];

    expect(array_map(fn (array $visit): ?string => $visit['acted']?->toDateTimeString(), $visits))->toEqualCanonicalizing([null, '2026-11-15 10:04:00']);
});

it('reads the latest rows of the posts a journey’s sessions opened, oldest first, and says the older were left out', function () {
    $older = analyticsVisit('busy-reader', ['network_day' => '2026-11-10', 'arrived_at' => '2026-11-10 10:00:00']);
    $newer = analyticsVisit('busy-reader', ['network_day' => '2026-11-15', 'arrived_at' => '2026-11-15 10:00:00']);
    analyticsVisitPost($older, 1);

    foreach (array_chunk(range(1, JourneyQuery::MOST_POST_ROWS), 250) as $chunk) {
        TrafficVisitPost::query()->insert(array_map(fn (int $n): array => [
            'visit_id' => $newer->id, 'post_id' => 1000 + $n, 'views' => 1, 'shared_at' => null, 'voted' => false, 'commented' => false,
            'first_at' => $newer->arrived_at->addSeconds($n)->toDateTimeString(),
        ], $chunk));
    }

    $journey = $this->journeys->journey([hash('sha256', 'busy-reader')], null, null);

    // The older session's row is the one left out; every visit was read, so the first needs no reading of its own.
    expect($journey['cut'])->toBeTrue()
        ->and($journey['first'])->toBeNull()
        ->and($journey['visits'])->toHaveCount(2)
        ->and($journey['posts'])->toHaveCount(JourneyQuery::MOST_POST_ROWS)
        ->and(array_unique(array_column($journey['posts'], 'visit')))->toBe([$newer->id])
        ->and($journey['posts'][0]['post'])->toBe(1001)
        ->and($journey['posts'][JourneyQuery::MOST_POST_ROWS - 1]['post'])->toBe(1000 + JourneyQuery::MOST_POST_ROWS);

    // As many rows as are read, and nothing is cut.
    TrafficVisitPost::query()->where('visit_id', $older->id)->delete();

    expect($this->journeys->journey([hash('sha256', 'busy-reader')], null, null)['cut'])->toBeFalse();
});
