<?php

use App\Actions\Analytics\SaveTrackingLinkAction;
use App\Actions\Traffic\ConfirmAccountAttributionAction;
use App\Actions\Traffic\MarkTrafficVisitAction;
use App\Actions\Traffic\RecordAccountSignupAction;
use App\Enums\AccountSignupMethod;
use App\Enums\AccountSignupPlace;
use App\Enums\GameStatus;
use App\Enums\TrackingLinkDestination;
use App\Enums\TrafficEvent;
use App\Enums\UserRole;
use App\Exceptions\Traffic\CannotManageTrafficException;
use App\Filament\Pages\Analytics\TrackingLinksTab;
use App\Filament\Pages\GrowthAnalyticsPage;
use App\Livewire\Admin\TrackingLinkEditor;
use App\Models\Game;
use App\Models\TrackingLink;
use App\Models\User;
use App\Queries\Analytics\TrackingLinksQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Analytics → Tracking links: the administrator's tagged addresses, made in
 * a drawer that names them by their tags until named, builds the address as
 * it is typed and says what is wrong by field; edited while no visit came
 * through them, renamed only once one did; never two with the same five
 * tags; duplicated as a new link from another's fields. A visit is a link's
 * when its session came by the link's tags, so a game started and an
 * account made after a link to the feed are the link's, as Campaigns counts
 * them. Saving opens the link's details; View analytics opens its campaign
 * and creative on Campaigns with a banner, its rows opened and marked, or
 * says no visit came yet. Nobody but an administrator who may manage traffic
 * sources.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00'));
    // The figures as they are at each read: the read cache has tests of its own (AnalyticsReadCacheTest).
    config(['analytics.read_cache_minutes' => 0]);
    $this->admin = User::factory()->admin()->create(['name' => 'Daria Kovaleva']);
    $this->actingAs($this->admin);
});

/** A link to the home feed for a Reddit post, as its drawer would save it, with the fields given instead. */
function saveTrackingLink(User $admin, array $input = [], ?int $linkId = null, ?int $lockVersion = null): TrackingLink
{
    return app(SaveTrackingLinkAction::class)->handle($admin, $linkId, [
        'destination' => 'page',
        'page' => '/',
        'source' => 'reddit',
        'medium' => 'organic_social',
        'campaign' => 'versus_launch',
        'content' => 'post_01',
        ...$input,
    ], $lockVersion);
}

/** The open Tracking links tab, read as the page reads it once it is in a browser. */
function trackingLinksPage(array $query = []): mixed
{
    return Livewire::withQueryParams(['tab' => 'links', ...$query])->test(GrowthAnalyticsPage::class)->call('load');
}

/** A visit of a session that came by a link's tags, its page run, on a day of the last 7. */
function visitThrough(TrackingLink $link, string $browser, array $attributes = []): void
{
    analyticsVisit($browser, [
        ...$link->tags()->columns(),
        'source_key' => TrackingLinksQuery::sourceKey($link),
        'campaign_key' => 'tag:'.$link->utm_campaign,
        'creative_key' => $link->tags()->creative(),
        ...$attributes,
    ]);
}

/** The validation messages a save refused with, by field. */
function trackingLinkErrors(Closure $save): array
{
    try {
        $save();
    } catch (ValidationException $exception) {
        return array_map(fn (array $messages): string => $messages[0], $exception->errors());
    }

    return [];
}

it('makes a link in the drawer, named by its tags until named, its address built as it is typed', function () {
    $editor = Livewire::test(TrackingLinkEditor::class)
        ->set('destination', 'page')
        ->call('pickPage', '/?sort=hot')
        ->call('pickSource', 'reddit')
        ->call('pickMedium', 'organic_social')
        ->set('campaign', 'versus_launch')
        ->set('content', 'Post_01');

    expect($editor->get('name'))->toBe('Reddit · versus_launch · post_01')
        ->and($editor->viewData('url'))->toBe(rtrim((string) config('app.url'), '/').'/?sort=hot&utm_source=reddit&utm_medium=organic_social&utm_campaign=versus_launch&utm_content=post_01')
        ->and($editor->viewData('title'))->toBe('Create tracking link')
        ->and($editor->viewData('foot'))->toBe(['text' => 'Ready to create', 'wrong' => false]);

    // A name typed is the link's own; the tags no longer move it.
    $editor->set('name', 'r/pics launch post')->set('content', 'post_02');

    expect($editor->get('name'))->toBe('r/pics launch post');

    $editor->call('save')->assertDispatched('tracking-link-saved', message: 'Tracking link created. It has no visits yet.');

    $link = TrackingLink::query()->sole();

    expect($link->only(['name', 'path', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'created_by_user_id', 'lock_version']))->toBe([
        'name' => 'r/pics launch post',
        'path' => '/?sort=hot',
        'utm_source' => 'reddit',
        'utm_medium' => 'organic_social',
        'utm_campaign' => 'versus_launch',
        'utm_content' => 'post_02',
        'utm_term' => '',
        'created_by_user_id' => $this->admin->id,
        'lock_version' => 1,
    ])
        ->and($link->destination)->toBe(TrackingLinkDestination::Page);
});

it('says what is wrong by field once a save asked, and writes nothing', function () {
    $editor = Livewire::test(TrackingLinkEditor::class)->set('campaign', 'Versus Launch');

    // Only a field that holds something says so before a save.
    expect($editor->viewData('wrong'))->toBe(['campaign' => 'Use lowercase letters, numbers, - or _ with no spaces.'])
        ->and($editor->viewData('foot'))->toBe(['text' => 'Required: destination, source, medium and campaign', 'wrong' => false]);

    $editor->call('save')->assertNotDispatched('tracking-link-saved');

    expect($editor->viewData('wrong'))->toBe([
        'destination' => 'Choose a game.',
        'source' => 'Choose a source.',
        'medium' => 'Choose a medium.',
        'campaign' => 'Use lowercase letters, numbers, - or _ with no spaces.',
    ])
        ->and($editor->viewData('foot'))->toBe(['text' => 'Fix the fields marked in red above', 'wrong' => true])
        ->and(TrackingLink::query()->exists())->toBeFalse();

    $archived = Game::factory()->status(GameStatus::Archived)->create();

    expect(trackingLinkErrors(fn () => saveTrackingLink($this->admin, ['destination' => 'game', 'game' => $archived->id])))->toBe(['destination' => 'Choose a game.'])
        ->and(trackingLinkErrors(fn () => saveTrackingLink($this->admin, ['destination' => 'custom', 'custom' => 'https://example.com/promo'])))->toBe(['destination' => 'Invalid URL. Use https:// and an address on '.SaveTrackingLinkAction::host().', or a path like /?sort=top.'])
        ->and(trackingLinkErrors(fn () => saveTrackingLink($this->admin, ['name' => str_repeat('n', 161)])))->toBe(['name' => 'Up to 160 characters.']);
});

it('leads a link to a game, one of the site’s pages, or a path of the site', function () {
    $game = Game::factory()->status(GameStatus::Live)->create(['title' => 'Trust Your Taste', 'slug' => 'trust-your-taste']);
    $host = SaveTrackingLinkAction::host();

    $toGame = saveTrackingLink($this->admin, ['destination' => 'game', 'game' => (string) $game->id, 'content' => 'a']);
    $toPath = saveTrackingLink($this->admin, ['destination' => 'custom', 'custom' => 'https://'.$host.'/u/anna?tab=posts&utm_source=old', 'content' => 'b']);

    expect([$toGame->destination, $toGame->game_id, $toGame->path])->toBe([TrackingLinkDestination::Game, $game->id, '/games/trust-your-taste'])
        ->and([$toPath->destination, $toPath->game_id, $toPath->path])->toBe([TrackingLinkDestination::Custom, null, '/u/anna?tab=posts'])
        ->and(TrackingLinksTab::url($toPath))->toBe(rtrim((string) config('app.url'), '/').'/u/anna?tab=posts&utm_source=reddit&utm_medium=organic_social&utm_campaign=versus_launch&utm_content=b');
});

it('edits a link while no visit came through it, at the version its drawer was drawn from', function () {
    $link = saveTrackingLink($this->admin);

    $editor = Livewire::test(TrackingLinkEditor::class, ['linkId' => $link->id]);

    expect($editor->viewData('title'))->toBe('Edit tracking link')
        ->and($editor->viewData('subtitle'))->toBe('TL-'.$link->id.' · no visits yet')
        ->and($editor->get('source'))->toBe('reddit')
        ->and($editor->get('content'))->toBe('post_01');

    $editor->set('campaign', 'spring')->call('pickMedium', 'paid_social')->call('save')
        ->assertDispatched('tracking-link-saved', id: $link->id, message: 'Link updated');

    expect($link->fresh()->only(['utm_campaign', 'utm_medium', 'lock_version', 'updated_by_user_id']))->toBe(['utm_campaign' => 'spring', 'utm_medium' => 'paid_social', 'lock_version' => 2, 'updated_by_user_id' => $this->admin->id]);

    // A drawer drawn before that save saves nothing, and Reload draws the link as it is.
    $stale = Livewire::test(TrackingLinkEditor::class, ['linkId' => $link->id]);
    saveTrackingLink($this->admin, ['campaign' => 'summer'], $link->id, 2);
    $stale->set('content', 'post_09')->call('save')
        ->assertDispatched('rg-admin-toast', message: 'Someone saved this link after you opened it. Reload to see their changes.', tone: 'error')
        ->assertSet('stale', true)
        ->call('reload')
        ->assertSet('stale', false)
        ->assertSet('campaign', 'summer');

    expect($link->fresh()->utm_content)->toBe('post_01');
});

it('locks a link’s address once a visit came through it, its name alone to change', function () {
    $link = saveTrackingLink($this->admin);
    $drawnBefore = Livewire::test(TrackingLinkEditor::class, ['linkId' => $link->id]);
    visitThrough($link, 'a');

    // A drawer drawn before the first visit can't change the address after it: it says why, and is drawn locked.
    $drawnBefore->set('campaign', 'spring')->set('name', 'Mine')->call('save')
        ->assertNotDispatched('tracking-link-saved')
        ->assertDispatched('rg-admin-toast', message: 'TL-'.$link->id.' got its first visit after you opened it, so its address is locked and only its name can change. Nothing was saved.', tone: 'error')
        ->assertSet('locked', true)
        ->assertSet('campaign', 'versus_launch')
        ->assertSet('name', 'Mine');

    $editor = Livewire::test(TrackingLinkEditor::class, ['linkId' => $link->id]);

    expect($editor->get('locked'))->toBeTrue()
        ->and($editor->viewData('title'))->toBe('Rename tracking link')
        ->and($editor->viewData('subtitle'))->toBe('TL-'.$link->id.' · has visits')
        ->and($editor->viewData('foot')['text'])->toBe('Only the internal name can change.');

    $editor->assertSee('This link already has visits, so its URL is locked to keep reports comparable.');

    // Picking another source or medium does nothing on a locked link.
    $editor->call('pickSource', 'facebook')->call('pickMedium', 'email')->set('name', 'r/pics launch')->call('save')
        ->assertDispatched('tracking-link-saved', id: $link->id, message: 'Link renamed');

    expect($link->fresh()->only(['name', 'utm_source', 'utm_medium', 'lock_version']))->toBe(['name' => 'r/pics launch', 'utm_source' => 'reddit', 'utm_medium' => 'organic_social', 'lock_version' => 2])
        ->and(fn () => saveTrackingLink($this->admin, ['campaign' => 'spring'], $link->id, 2))
        ->toThrow(CannotManageTrafficException::class, 'TL-'.$link->id.' got its first visit after you opened it, so its address is locked and only its name can change. Nothing was saved.')
        ->and(trackingLinkErrors(fn () => saveTrackingLink($this->admin, ['name' => ' '], $link->id, 2)))->toBe(['name' => 'Enter a link name.']);
});

it('shows a locked link’s game as it is, another game asked for leaving its address alone', function () {
    $game = Game::factory()->status(GameStatus::Live)->create(['title' => 'Trust Your Taste', 'slug' => 'trust-your-taste']);
    $other = Game::factory()->status(GameStatus::Live)->create(['title' => 'Spot the Fake', 'slug' => 'spot-the-fake']);
    $link = saveTrackingLink($this->admin, ['destination' => 'game', 'game' => (string) $game->id]);
    visitThrough($link, 'a');

    $editor = Livewire::test(TrackingLinkEditor::class, ['linkId' => $link->id]);

    // The game reads as a locked value, with no picker to change it.
    $editor->assertSeeHtml('data-testid="analytics-link-locked-game"')
        ->assertSee('Trust Your Taste')
        ->assertDontSeeHtml('id="rg-admin-tracking-link-game-trigger"');

    $editor->set('game', (string) $other->id)->assertSet('game', (string) $game->id);

    expect($editor->viewData('url'))->toBe(TrackingLinksTab::url($link));

    $editor->set('name', 'Taste launch')->call('save')
        ->assertDispatched('tracking-link-saved', id: $link->id, message: 'Link renamed');

    expect($link->fresh()->only(['name', 'game_id', 'path']))->toBe(['name' => 'Taste launch', 'game_id' => $game->id, 'path' => '/games/trust-your-taste']);
});

it('refuses a set of tags another link carries, saying which, as it is typed and on save', function () {
    $first = saveTrackingLink($this->admin);

    // The content is kept in lower case, so another case is the same tag.
    expect(trackingLinkErrors(fn () => saveTrackingLink($this->admin, ['content' => 'POST_01'])))
        ->toBe(['content' => 'Another link already uses these tags: TL-'.$first->id.'. Change the content or term to tell them apart.']);

    $editor = Livewire::test(TrackingLinkEditor::class)->set('destination', 'page')->call('pickSource', 'reddit')->call('pickMedium', 'organic_social')->set('campaign', 'versus_launch')->set('content', 'post_01');

    expect($editor->viewData('holding'))->toBe('Another link already uses these tags: TL-'.$first->id.' (“Reddit · versus_launch · post_01”). Visits would count for it alone. Change the content or term to tell them apart.')
        ->and($editor->viewData('foot'))->toBe(['text' => 'Another link carries these tags', 'wrong' => false]);

    $editor->call('save')->assertNotDispatched('tracking-link-saved');

    expect($editor->viewData('wrong')['content'])->toBe('Another link already uses these tags: TL-'.$first->id.'. Change the content or term to tell them apart.');

    $editor->set('term', 'quiz')->call('save')->assertDispatched('tracking-link-saved');

    // Its own tags are no other link's.
    expect(saveTrackingLink($this->admin, ['name' => 'Renamed'], $first->id, 1)->name)->toBe('Renamed')
        ->and(TrackingLink::query()->count())->toBe(2);
});

it('duplicates a link as a new one drawn from its fields', function () {
    $link = saveTrackingLink($this->admin, ['name' => 'r/pics launch post']);
    visitThrough($link, 'a');

    $page = trackingLinksPage()->call('duplicateLink', $link->id);

    expect($page->get('edit'))->toBe('copy-'.$link->id)
        ->and($page->viewData('data')['editing'])->toBe(['linkId' => 0, 'copyOf' => $link->id, 'key' => 'copy-'.$link->id]);

    $editor = Livewire::test(TrackingLinkEditor::class, ['copyOf' => $link->id]);

    expect($editor->viewData('title'))->toBe('Duplicate tracking link')
        ->and($editor->get('locked'))->toBeFalse()
        ->and($editor->get('name'))->toBe('r/pics launch post (copy)')
        ->and($editor->viewData('holding'))->not->toBeNull();

    $editor->set('content', 'post_02')->call('save')->assertDispatched('tracking-link-saved', message: 'Tracking link created. It has no visits yet.');

    expect(TrackingLink::query()->latest('id')->first()->only(['name', 'utm_content', 'path']))->toBe(['name' => 'r/pics launch post (copy)', 'utm_content' => 'post_02', 'path' => '/'])
        ->and($link->fresh()->utm_content)->toBe('post_01');
});

it('credits a link to the feed with the game started and the account made after it, as Campaigns counts them', function () {
    CarbonImmutable::setTestNow('2026-11-17 12:00:00');
    recordTraffic(site: true);
    $draft = publishableGameDraft();
    liveGame($draft);
    allowGames();
    $link = saveTrackingLink($this->admin);
    $path = substr(TrackingLinksTab::url($link), strlen(TrackingLinksTab::origin()));
    // A visitor, not the administrator: staff leave no unpaid visits.
    $this->app['auth']->forgetGuards();

    pageRuns($path);
    CarbonImmutable::setTestNow('2026-11-17 12:05:00');
    $visitId = pageRuns('/games/'.$draft['game']->slug, ['Referer' => url('/')]);
    app(MarkTrafficVisitAction::class)->handle((int) $visitId, TrafficEvent::Started, CarbonImmutable::now());
    $member = User::factory()->create(['email_verified_at' => now()]);
    app(RecordAccountSignupAction::class)->handle($member, browserHashOf(OPEN_TOKEN), AccountSignupMethod::Email, AccountSignupPlace::Game, CarbonImmutable::now());
    app(ConfirmAccountAttributionAction::class)->handle($member->id, CarbonImmutable::now());
    CarbonImmutable::setTestNow('2026-11-18 12:00:00');
    $this->actingAs($this->admin);

    $row = trackingLinksPage()->viewData('data')['rows'][0];
    $campaigns = Livewire::withQueryParams(['tab' => 'campaigns'])->test(GrowthAnalyticsPage::class)->call('load')->viewData('data')['rows'];

    expect([$row['visitors'], $row['accounts']['value'], $row['visited'], $row['editLabel']])->toBe(['1', '1', true, 'Rename'])
        ->and($campaigns[0]['name'])->toBe('versus_launch')
        ->and([$campaigns[0]['cells'][1]['value'], $campaigns[0]['cells'][3]['value'], $campaigns[0]['cells'][5]['value']])->toBe(['1', '1', '1']);

    $figures = app(TrackingLinksQuery::class)->figures([$link], analyticsFilter('7d'));

    expect([$figures[$link->id]->visitors, $figures[$link->id]->starters, $figures[$link->id]->accounts])->toBe([1, 1, 1]);
});

it('lists the links with what each brought, the period’s people of each and none for a link never visited', function () {
    $game = Game::factory()->status(GameStatus::Live)->create(['title' => 'Trust Your Taste', 'slug' => 'trust-your-taste']);
    $visited = saveTrackingLink($this->admin, ['destination' => 'game', 'game' => $game->id, 'name' => 'Reddit · r/pics']);
    $this->travel(2)->days();
    $fresh = saveTrackingLink($this->admin, ['source' => 'email', 'medium' => 'email', 'campaign' => 'weekly_40', 'content' => 'hero', 'term' => 'quiz', 'name' => 'Weekly hero']);

    foreach (range(1, 40) as $n) {
        visitThrough($visited, 'p-'.$n, $n <= 4 ? ['signup_at' => '2026-11-15 11:00:00', 'account_at' => '2026-11-15 11:00:00'] : []);
    }

    $data = trackingLinksPage()->viewData('data');
    $line = fn (array $row): string => implode(' | ', [$row['name'], $row['new'] ? 'New' : '-', $row['destination'], $row['source'].' · '.$row['medium'], $row['campaign'].' · '.$row['creative'], $row['visitors'], $row['accounts']['value'].' '.($row['accounts']['rate'] ?? '-'), $row['activated']['value'], $row['created'].' · '.$row['by']]);

    expect(array_map($line, $data['rows']))->toBe([
        'Weekly hero | New | Home feed · / | Email · email | weekly_40 · hero · quiz | No visits yet | — - | — | 20 Nov 2026 · Daria Kovaleva',
        'Reddit · r/pics | - | Trust Your Taste · /games/trust-your-taste | Reddit · organic_social | versus_launch · post_01 | 40 | 4 10.0% of new visitors | 0 | 18 Nov 2026 · Daria Kovaleva',
    ])
        ->and($data['count'])->toBe('2 links')
        ->and($data['rows'][1]['url'])->toBe(TrackingLinksTab::url($visited))
        ->and($data['rows'][0]['editLabel'])->toBe('Edit')
        ->and(trackingLinksPage(['q' => 'weekly'])->viewData('data')['count'])->toBe('1 link')
        ->and(trackingLinksPage(['q' => 'nothing'])->viewData('data')['none'])->toBe('No tracking links match these filters.')
        ->and(trackingLinksPage(['source' => 'email:email'])->viewData('data')['rows'][0]['id'])->toBe($fresh->id);

    TrackingLink::query()->delete();

    expect(trackingLinksPage()->viewData('data')['none'])->toBe('No tracking links yet. Create one for a post, an ad or an email, and the visitors who come through it are counted here.');
});

it('opens the link’s details after a save, with its figures, tags and the way to its campaign', function () {
    $link = saveTrackingLink($this->admin);

    $page = trackingLinksPage(['edit' => 'new'])->dispatch('tracking-link-saved', id: $link->id, message: 'Tracking link created. It has no visits yet.');

    $page->assertSet('edit', '')
        ->assertDispatched('rg-admin-toast', message: 'Tracking link created. It has no visits yet.', tone: 'success');

    // The details open in a second step, once the editor's drawer has left the page.
    expect(collect($page->effects['xjs'] ?? [])->flatten()->implode(' '))->toContain('$wire.set(\'show\', "'.$link->id.'")');

    $page->set('show', (string) $link->id);

    $detail = $page->viewData('data')['detail'];

    expect($detail['name'])->toBe('Reddit · versus_launch · post_01')
        ->and($detail['sub'])->toBe('TL-'.$link->id.' · created 18 Nov 2026 by Daria Kovaleva')
        ->and($detail['empty'])->toBeTrue()
        ->and($detail['url'])->toBe(TrackingLinksTab::url($link))
        ->and($detail['editLabel'])->toBe('Edit')
        ->and($detail['analytics'])->toBe(GrowthAnalyticsPage::getUrl(['tab' => 'campaigns', 'campaign' => 'tag:versus_launch', 'creative' => 'post_01', 'link' => $link->id]))
        ->and(array_column($detail['meta'], 'value', 'label'))->toBe([
            'Destination' => 'Home feed · /',
            'Source' => 'Reddit',
            'Medium' => 'organic_social',
            'Campaign' => 'versus_launch',
            'Creative' => 'post_01',
            'Term' => '—',
            'Created' => '18 Nov 2026 · Daria Kovaleva',
        ]);

    $page->assertSee('No visits yet')->assertSee('View in Campaigns');

    foreach (range(1, 40) as $n) {
        visitThrough($link, 'p-'.$n, ['started_at' => '2026-11-15 10:01:00', 'device' => 'desktop']);
    }

    $detail = trackingLinksPage(['show' => $link->id, 'device' => 'desktop'])->viewData('data')['detail'];

    expect($detail['empty'])->toBeFalse()
        ->and(array_map(fn (array $metric): string => $metric['label'].' '.$metric['value'].' '.$metric['note'], $detail['metrics']))->toBe([
            'Visitors 40 Unique visitors',
            'Game starts 40 100.0% of visitors',
            'Completions 0 0.0% of starters',
            'Registrations 0 0.0% of new visitors',
            'Activated 0 0.0% of new visitors',
        ])
        ->and($detail['period'])->toBe('Last 30 days · Desktop · counted by RateGuru')
        ->and($detail['editLabel'])->toBe('Rename');
});

it('opens a link’s campaign on Campaigns with a banner, its campaign and creative opened and marked', function () {
    $link = saveTrackingLink($this->admin);
    visitThrough($link, 'a');
    visitThrough($link, 'b');
    // Another link's people of the same campaign, by another creative, come first.
    foreach (range(1, 3) as $n) {
        analyticsVisit('other-'.$n, ['utm_source' => 'reddit', 'utm_medium' => 'organic_social', 'utm_campaign' => 'versus_launch', 'utm_content' => 'post_02', 'source_key' => 'reddit:organic', 'campaign_key' => 'tag:versus_launch', 'creative_key' => 'post_02']);
    }

    $url = TrackingLinksTab::analyticsUrl($link, analyticsFilter('30d'));
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $page = Livewire::withQueryParams($query)->test(GrowthAnalyticsPage::class)->call('load');
    $data = $page->viewData('data');
    $rows = array_map(fn (array $row): string => $row['level'].' '.$row['name'].($row['open'] ? ' open' : '').($row['marked'] ? ' marked' : ''), $data['rows']);

    expect($query)->toBe(['tab' => 'campaigns', 'campaign' => 'tag:versus_launch', 'creative' => 'post_01', 'link' => (string) $link->id])
        ->and($data['link'])->toBe(['text' => 'Showing campaign performance for TL-'.$link->id.' (reddit · organic_social · versus_launch · post_01). Its row is marked.', 'visits' => true])
        ->and($rows)->toBe(['0 versus_launch open marked', '1 post_02', '1 post_01 open marked']);

    $page->assertSee('Back to tracking links')
        ->call('backToLinks')
        ->assertSet('tab', 'links')
        ->assertSet('campaign', '')
        ->assertSet('creative', '')
        ->assertSet('link', '');

    // Another campaign chosen drops the link and its banner.
    $other = Livewire::withQueryParams($query)->test(GrowthAnalyticsPage::class)->call('load')->set('campaign', 'tag:spring');

    expect($other->get('link'))->toBe('')
        ->and(Livewire::withQueryParams([...$query, 'campaign' => 'tag:spring'])->test(GrowthAnalyticsPage::class)->call('load')->viewData('data')['link'])->toBeNull();
});

it('says a link no visit came through yet, with its campaign below when another link brought it, and when none did', function () {
    $link = saveTrackingLink($this->admin);
    $query = ['tab' => 'campaigns', 'campaign' => 'tag:versus_launch', 'creative' => 'post_01', 'link' => $link->id];
    // The site records visits; none came by this campaign yet.
    analyticsVisit('someone');

    $none = Livewire::withQueryParams($query)->test(GrowthAnalyticsPage::class)->call('load');

    expect($none->viewData('data')['empty'])->toBeTrue()
        ->and($none->viewData('data')['link'])->toBe(['text' => 'No visits have arrived through TL-'.$link->id.' (reddit · organic_social · versus_launch · post_01) yet. It will appear here after its first visit.', 'visits' => false]);

    $none->assertSee('It will appear here after its first visit.')->assertSee('Back to tracking links');

    analyticsVisit('other', ['utm_source' => 'facebook', 'utm_medium' => 'paid_social', 'utm_campaign' => 'versus_launch', 'source_key' => 'facebook:paid', 'campaign_key' => 'tag:versus_launch']);

    $data = Livewire::withQueryParams($query)->test(GrowthAnalyticsPage::class)->call('load')->viewData('data');

    expect($data['link']['text'])->toBe('No visits have arrived through TL-'.$link->id.' (reddit · organic_social · versus_launch · post_01) yet. Below is the existing campaign versus_launch, which uses the same utm_campaign.')
        ->and($data['rows'][0])->name->toBe('versus_launch')->marked->toBeTrue();
});

it('draws the tab before any visit was recorded, saying recording is off', function () {
    recordTraffic(capture: false);
    saveTrackingLink($this->admin);

    $page = trackingLinksPage();

    expect($page->viewData('state'))->toBe('ready')
        ->and(array_column($page->viewData('notices'), 'text'))->toBe(['Record visits is off, so Analytics has nothing new to count. Switch it on under Traffic sources → Sources → Tracking.'])
        ->and($page->viewData('data')['rows'][0]['visitors'])->toBe('No visits yet');

    $page->assertSee('Create tracking link')->assertSee('Tracking links');
});

it('keeps the drawer, the details and the search to the Tracking links tab, reading anything else as none', function () {
    $link = saveTrackingLink($this->admin);

    trackingLinksPage(['edit' => 'copy-x', 'show' => 'abc', 'q' => str_repeat('q', 200)])
        ->assertSet('edit', '')->assertSet('show', '')->assertSet('q', str_repeat('q', 120));

    Livewire::withQueryParams(['tab' => 'overview', 'edit' => 'new', 'show' => $link->id, 'q' => 'x', 'link' => $link->id])->test(GrowthAnalyticsPage::class)
        ->assertSet('edit', '')->assertSet('show', '')->assertSet('q', '')->assertSet('link', '');

    // A drawer of a link gone is none; the details close while a drawer is open.
    expect(trackingLinksPage(['edit' => '999999'])->viewData('data')['editing'])->toBeNull()
        ->and(trackingLinksPage(['edit' => (string) $link->id, 'show' => $link->id])->get('show'))->toBe('');
});

it('reads a bounded number of queries however many links there are', function () {
    $queries = function (): int {
        $page = trackingLinksPage();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $page->call('$refresh');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    visitThrough(saveTrackingLink($this->admin), 'a');
    $one = $queries();

    foreach (range(2, 12) as $n) {
        visitThrough(saveTrackingLink($this->admin, ['content' => 'post_'.$n]), 'v-'.$n);
    }

    expect($queries())->toBe($one)
        ->and($one)->toBeLessThanOrEqual(20);
});

it('answers only an administrator who may manage traffic sources, every request asking again', function () {
    $link = saveTrackingLink($this->admin);
    $page = trackingLinksPage();
    $editor = Livewire::test(TrackingLinkEditor::class)->set('destination', 'page')->call('pickSource', 'reddit')->call('pickMedium', 'email')->set('campaign', 'spring');

    // The same session, demoted since the page was drawn.
    $this->admin->forceFill(['role' => UserRole::Moderator])->save();

    $page->call('createLink')->assertForbidden();
    $editor->call('save')->assertForbidden();
    Livewire::test(TrackingLinkEditor::class, ['linkId' => $link->id])->assertForbidden();

    expect(TrackingLink::query()->count())->toBe(1)
        ->and(fn () => saveTrackingLink($this->admin->fresh(), ['content' => 'post_02']))
        ->toThrow(CannotManageTrafficException::class, 'You can’t manage traffic sources any more, so nothing was saved.');

    $this->actingAs(User::factory()->moderator()->create());
    $this->get(GrowthAnalyticsPage::getUrl(['tab' => 'links', 'edit' => 'new']))->assertForbidden();
});
