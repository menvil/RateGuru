<?php

use App\Enums\GameStatus;
use App\Enums\TrackingLinkDestination;
use App\Models\Category;
use App\Models\Game;
use App\Models\TrackingLink;
use App\Models\TrafficCampaign;
use App\Models\TrafficVisit;
use App\Queries\Analytics\AnalyticsVisitsQuery;
use App\Queries\Analytics\TrackingLinksQuery;
use Carbon\CarbonImmutable;

/*
 * The tracking links and what they brought, alike on every engine: a search
 * over names and tags taken literally, any case; the page's source and
 * campaign narrowing the list; a visit a link's when its session came by
 * the link's five tags, a link's empty content or term being a visit's none;
 * whether any visit ever came through a link; the link that already holds a
 * set of tags; and a draft read as its drawer holds it.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00'));
});

function trackingLinks(): TrackingLinksQuery
{
    return app(TrackingLinksQuery::class);
}

/** A link with the tags given; the others those of a Reddit launch post. */
function trackingLinkWith(array $tags, string $name = 'Reddit post', string $created = '2026-11-10 10:00:00'): TrackingLink
{
    return TrackingLink::factory()->create(['name' => $name, 'utm_content' => '', 'utm_term' => '', ...$tags, 'created_at' => $created, 'updated_at' => $created]);
}

/** A visit of a session that came by the tags given, its page run, on the network day given. */
function visitTagged(string $browser, array $tags, array $attributes = []): void
{
    analyticsVisit($browser, [
        'utm_source' => $tags['utm_source'] ?? 'reddit',
        'utm_medium' => $tags['utm_medium'] ?? 'organic_social',
        'utm_campaign' => $tags['utm_campaign'] ?? 'launch',
        'utm_content' => $tags['utm_content'] ?? null,
        'utm_term' => $tags['utm_term'] ?? null,
        'source_key' => 'reddit:organic',
        'campaign_key' => 'tag:'.($tags['utm_campaign'] ?? 'launch'),
        ...$attributes,
    ]);
}

it('searches names, sources and tags literally and in any case, the newest first', function () {
    $percent = trackingLinkWith(['utm_campaign' => 'launch', 'utm_content' => 'post_01'], '100% Reddit', '2026-11-01 10:00:00');
    $underscore = trackingLinkWith(['utm_campaign' => 'spring_sale'], 'Spring', '2026-11-12 10:00:00');
    $mail = trackingLinkWith(['utm_source' => 'email', 'utm_medium' => 'email', 'utm_campaign' => 'weekly', 'utm_term' => 'Hero Button'], 'Weekly', '2026-11-14 10:00:00');
    $ids = fn (string $search): array => trackingLinks()->list($search, analyticsFilter('30d'))['links']->pluck('id')->all();

    expect($ids(''))->toBe([$mail->id, $underscore->id, $percent->id])
        ->and($ids('100%'))->toBe([$percent->id])
        // % and _ match only themselves.
        ->and($ids('%'))->toBe([$percent->id])
        ->and($ids('launc_'))->toBe([])
        ->and($ids('_sale'))->toBe([$underscore->id])
        ->and($ids('EMAIL'))->toBe([$mail->id])
        ->and($ids('hero button'))->toBe([$mail->id])
        ->and($ids('POST_01'))->toBe([$percent->id])
        ->and(trackingLinks()->list('', analyticsFilter('30d'))['total'])->toBe(3);
});

it('narrows the list to the page’s source and campaign, a paid campaign holding no link', function () {
    $reddit = trackingLinkWith(['utm_campaign' => 'launch']);
    $ads = trackingLinkWith(['utm_source' => 'google', 'utm_medium' => 'paid_search', 'utm_campaign' => 'launch']);
    $mail = trackingLinkWith(['utm_source' => 'email', 'utm_medium' => 'email', 'utm_campaign' => 'weekly']);
    $ids = fn (?string $source, ?string $campaign): array => trackingLinks()->list('', analyticsFilter('30d', source: $source, campaign: $campaign))['links']->pluck('id')->sort()->values()->all();

    expect($ids('reddit:organic', null))->toBe([$reddit->id])
        ->and($ids('google:paid', null))->toBe([$ads->id])
        ->and($ids('email:email', null))->toBe([$mail->id])
        ->and($ids(null, 'tag:launch'))->toBe([$reddit->id, $ads->id])
        ->and($ids('reddit:organic', 'tag:weekly'))->toBe([])
        ->and($ids(null, 'paid:'.TrafficCampaign::factory()->create()->id))->toBe([]);
});

it('counts a link the people whose session came by its five tags, its empty ones a visit’s none', function () {
    $post = trackingLinkWith(['utm_campaign' => 'launch', 'utm_content' => 'post_01']);
    $bare = trackingLinkWith(['utm_campaign' => 'launch']);
    $idle = trackingLinkWith(['utm_campaign' => 'launch', 'utm_content' => 'post_02']);

    visitTagged('a', ['utm_content' => 'post_01']);
    visitTagged('a', ['utm_content' => 'post_01'], ['started_at' => '2026-11-15 10:01:00']);
    visitTagged('b', ['utm_content' => 'post_01'], ['signup_at' => '2026-11-15 11:00:00', 'account_at' => '2026-11-15 11:00:00']);
    visitTagged('c', []);
    // Another term, another source, a day before the period: no link's here.
    visitTagged('d', ['utm_content' => 'post_01', 'utm_term' => 'quiz']);
    visitTagged('e', ['utm_content' => 'post_01', 'utm_source' => 'facebook']);
    visitTagged('f', ['utm_content' => 'post_01'], ['network_day' => '2026-11-01', 'arrived_at' => '2026-11-01 10:00:00']);

    $figures = trackingLinks()->figures(TrackingLink::query()->get(), analyticsFilter('7d'));

    expect(array_keys($figures))->toEqualCanonicalizing([$post->id, $bare->id])
        ->and([$figures[$post->id]->visitors, $figures[$post->id]->starters, $figures[$post->id]->accounts])->toBe([2, 1, 1])
        ->and($figures[$bare->id]->visitors)->toBe(1)
        ->and(trackingLinks()->figures([], analyticsFilter('7d')))->toBe([]);

    // Whether any visit ever came, whatever the period: what locks a link.
    expect(trackingLinks()->hasVisits($post))->toBeTrue()
        ->and(trackingLinks()->hasVisits($bare))->toBeTrue()
        ->and(trackingLinks()->hasVisits($idle))->toBeFalse()
        ->and(trackingLinks()->list('', analyticsFilter('7d'))['links']->mapWithKeys(fn (TrackingLink $link): array => [$link->id => $link->getAttribute('visit_id') !== null])->all())
        ->toBe([$idle->id => false, $bare->id => true, $post->id => true]);
});

it('counts a link its own people however many bigger sets of tags its campaign holds', function () {
    $post = trackingLinkWith(['utm_campaign' => 'launch', 'utm_content' => 'post_01']);
    visitTagged('a', ['utm_content' => 'post_01']);

    // As many other creatives of the campaign as a split lists, each with more people than the link.
    $crowd = collect(analyticsVisit('crowd', ['utm_source' => 'reddit', 'utm_medium' => 'organic_social', 'utm_campaign' => 'launch', 'utm_content' => 'ad_0', 'source_key' => 'reddit:organic', 'campaign_key' => 'tag:launch'])->getAttributes())->except('id')->all();
    $rows = [];

    foreach (range(1, AnalyticsVisitsQuery::MOST_GROUPS) as $creative) {
        foreach (['x', 'y'] as $browser) {
            $rows[] = [...$crowd, 'utm_content' => 'ad_'.$creative, 'visitor_hash' => hash('sha256', $browser.$creative)];
        }
    }

    foreach (array_chunk($rows, 200) as $chunk) {
        TrafficVisit::query()->insert($chunk);
    }

    $figures = trackingLinks()->figures([$post], analyticsFilter('7d'));

    expect(array_keys($figures))->toBe([$post->id])
        ->and($figures[$post->id]->visitors)->toBe(1);
});

it('finds the link that already holds a set of tags, besides the one named', function () {
    $link = trackingLinkWith(['utm_campaign' => 'launch', 'utm_content' => 'post_01']);
    $tags = ['utm_source' => 'reddit', 'utm_medium' => 'organic_social', 'utm_campaign' => 'launch', 'utm_content' => 'post_01', 'utm_term' => ''];

    expect(trackingLinks()->holding($tags)?->id)->toBe($link->id)
        ->and(trackingLinks()->holding($tags, $link->id))->toBeNull()
        ->and(trackingLinks()->holding([...$tags, 'utm_term' => 'quiz']))->toBeNull();
});

it('reads a draft as its drawer holds it: where it leads, its tags, and what is wrong by field', function () {
    $game = Game::factory()->status(GameStatus::Live)->create(['title' => 'Trust Your Taste', 'slug' => 'trust-your-taste']);
    $archived = Game::factory()->status(GameStatus::Archived)->create();
    Category::factory()->create(['slug' => 'dogs', 'name' => 'Dogs', 'is_active' => true]);
    $draft = fn (array $input): array => trackingLinks()->draft($input, 'rateguru.com');

    expect($draft(['destination' => 'game', 'game' => (string) $game->id, 'source' => 'other', 'source_custom' => ' TikTok ', 'medium' => 'paid_social', 'campaign' => 'spring', 'content' => ' Video_01 ', 'term' => 'Taste Quiz']))
        ->toBe([
            'destination' => TrackingLinkDestination::Game,
            'game_id' => $game->id,
            'path' => '/games/trust-your-taste',
            'tags' => ['utm_source' => 'tiktok', 'utm_medium' => 'paid_social', 'utm_campaign' => 'spring', 'utm_content' => 'video_01', 'utm_term' => 'taste quiz'],
            'errors' => [],
        ])
        ->and(trackingLinks()->pages())->toBe(['/' => 'Home feed', '/?sort=hot' => 'Hot posts', '/?sort=top' => 'Top posts', '/?category%5B0%5D=dogs' => 'Category · Dogs'])
        ->and($draft(['destination' => 'page', 'page' => '/?category%5B0%5D=dogs', 'source' => 'email', 'medium' => 'email', 'campaign' => 'w1'])['path'])->toBe('/?category%5B0%5D=dogs');

    expect($draft([])['errors'])->toBe(['destination' => 'Choose a game.', 'source' => 'Choose a source.', 'medium' => 'Choose a medium.', 'campaign' => 'Enter a campaign identifier.'])
        ->and($draft(['destination' => 'game', 'game' => $archived->id])['errors']['destination'])->toBe('Choose a game.')
        ->and($draft(['destination' => 'page', 'page' => '/admin'])['errors']['destination'])->toBe('Choose a page.')
        ->and($draft(['destination' => 'custom', 'custom' => 'https://example.com/'])['errors']['destination'])->toBe('Invalid URL. Use https:// and an address on rateguru.com, or a path like /?sort=top.')
        ->and($draft(['source' => 'other'])['errors']['source'])->toBe('Enter a source name.')
        ->and($draft(['source' => 'other', 'source_custom' => 'tik tok'])['errors']['source'])->toBe('Use lowercase letters, numbers, - or _ for the source.')
        ->and($draft(['source' => 'myspace'])['errors']['source'])->toBe('Choose a source.')
        ->and($draft(['medium' => 'cpc'])['errors']['medium'])->toBe('Choose a medium.')
        ->and($draft(['campaign' => 'Versus Launch'])['errors']['campaign'])->toBe('Use lowercase letters, numbers, - or _ with no spaces.')
        ->and($draft(['content' => '{creative}', 'term' => str_repeat('t', 121)])['errors'])->toMatchArray([
            'content' => 'This reads as an ad network’s placeholder, which a visit doesn’t keep. Use plain text.',
            'term' => 'Up to 120 characters.',
        ]);
});
