<?php

use App\Actions\Games\ApplyGameTrafficAction;
use App\Actions\Games\ChangeGameFlowAction;
use App\Actions\Games\ClaimGuestGameAttemptsAction;
use App\Actions\Games\PublishGameVersionAction;
use App\Enums\AccountSignupPlace;
use App\Enums\GameAccountOutcome;
use App\Enums\GameAttemptStatus;
use App\Enums\GameAudience;
use App\Enums\GameEventType;
use App\Enums\GameFlowStepType;
use App\Enums\TrafficChannel;
use App\Enums\TrafficDevice;
use App\Filament\Pages\TrafficSourcesPage;
use App\Filament\Resources\Games\GameResource;
use App\Filament\Resources\Games\Pages\GameAnalytics;
use App\Livewire\Games\PlayGame;
use App\Livewire\Posts\PostVoting;
use App\Livewire\Voting\RatingVoting;
use App\Models\AccountAttribution;
use App\Models\Category;
use App\Models\GameAssignment;
use App\Models\GameAttempt;
use App\Models\GameEmailCapture;
use App\Models\GameEvent;
use App\Models\GameFlow;
use App\Models\GameRoundOption;
use App\Models\GameTrafficPeriod;
use App\Models\GameVersion;
use App\Models\Post;
use App\Models\ProjectSettings;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\TrafficSetting;
use App\Models\TrafficSource;
use App\Models\TrafficVisit;
use App\Models\User;
use App\Support\Games\Flow\GameFlowTemplate;
use App\Support\Games\Play\GameSessionAttempts;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Growth → Games → Analytics reads what playing recorded: two guests open a
 * live game, one plays it through, and the tab shows exactly that — the
 * figures, the funnel, the round reached, the result earned — per group, and
 * nothing for a group nobody of which came.
 */
beforeEach(function () {
    ProjectSettings::factory()->create();
    $this->draft = publishableGameDraft(1);
});

/** A guest's browser (its cookie token) plays the one-round game to its Full Result, on whichever version the traffic gives it. */
function guestAtResult(array $draft, string $token): Testable
{
    return Livewire::withCookie((string) config('games.visitor_cookie.name'), $token)
        ->test(PlayGame::class, ['slug' => $draft['game']->slug])
        ->call('init')->call('continue')->call('choose', 0)->call('next')
        ->assertSee('data-testid="game-step-full_result"', false);
}

it('says there is nothing to measure before a version is published', function () {
    $this->actingAs($this->draft['admin'])
        ->get(GameResource::getUrl('analytics', ['record' => $this->draft['game']]))
        ->assertOk()
        ->assertSee('Nothing to measure yet')
        ->assertSee('Go to Versions');
});

it('says nobody came yet while a live game has no visitors', function () {
    liveGame($this->draft);

    $this->actingAs($this->draft['admin'])
        ->get(GameResource::getUrl('analytics', ['record' => $this->draft['game']]))
        ->assertOk()
        ->assertSee('No visitors here yet')
        ->assertSee('Traffic periods · Anonymous visitors');
});

it('shows what two guests did: one opened the game, the other played it through', function () {
    $version = liveGame($this->draft);
    $cookie = (string) config('games.visitor_cookie.name');

    Livewire::withCookie($cookie, str_repeat('a', 40))->test(PlayGame::class, ['slug' => $this->draft['game']->slug])
        ->call('init')->call('continue')->call('choose', 0)->call('next')
        ->assertSee('data-testid="game-step-full_result"', false);
    session()->flush();
    Livewire::withCookie($cookie, str_repeat('b', 40))->test(PlayGame::class, ['slug' => $this->draft['game']->slug])
        ->call('init');
    session()->flush();

    $this->actingAs($this->draft['admin']);
    $profile = $version->read()->profileFor(1);

    $page = Livewire::test(GameAnalytics::class, ['record' => $this->draft['game']->id])
        // Each audience with its visitors over the period, beside the switch.
        ->assertViewHas('audienceCounts', ['anonymous' => '2', 'authenticated' => '0'])
        ->assertSeeInOrder(['Visitors', '2', 'People who opened the game'])
        ->assertSeeInOrder(['Game started', '1', '50.0% of visitors'])
        ->assertSeeInOrder(['Completed', '1', '100.0% of starters'])
        ->assertSeeInOrder(['Goal reached', '0', '0.0% of completed'])
        // No traffic was recorded, so there is no worth to show.
        ->assertSee('No visits recorded in Traffic sources')
        // Nobody came in the 30 days before, so there is nothing to move against.
        ->assertSee('No earlier data')
        // No version asks for an email, so the funnel has no step for it.
        ->assertDontSee('Email captured')
        ->assertSee('Rounds reached · V1')
        ->assertSee('V1 has the most attempts here')
        ->assertSeeInOrder(['Round 1', '100.0%'])
        ->assertSeeInOrder(['Results · V1', $profile->name->english(), '100.0%'])
        ->assertSeeInOrder(['Break down by', 'V1', $this->draft['set']->name.' · '.$this->draft['flow']->name])
        ->assertSee('1 started · 1 completed');

    // A row's bar comes before its count, so the funnel is read from its rows rather than the markup.
    expect(collect($page->viewData('funnel'))->pluck('count', 'label')->all())
        ->toBe(['Visitors' => '2', 'Game started' => '1', 'Completed' => '1', 'Sign-up begun' => '0', 'Goal reached' => '0', 'Account confirmed' => '0'])
        // Each rate names what it is a share of: signing up is of the people who finished, not of those who began it.
        ->and(collect($page->viewData('funnel'))->pluck('rateOf', 'label')->all())
        ->toBe(['Visitors' => null, 'Game started' => 'of Visitors', 'Completed' => 'of Game started', 'Sign-up begun' => 'of Completed', 'Goal reached' => 'of Game started', 'Account confirmed' => 'of Completed']);

    // The funnel counts people, and says so; so does Completed above it, for anonymous visitors.
    $page->assertSee('each counted once')
        ->assertDontSee('counts the attempts started')
        ->assertDontSee('counts plays')
        ->assertSeeHtml('<span class="rg-admin-table__meta">of Game started</span>');

    // Split by flow: the flow and the versions made from it.
    $page->set('breakdown', 'flow')
        ->assertSeeInOrder([$this->draft['flow']->name, 'Used by V1'])
        // Nobody signed in came.
        ->set('audience', 'authenticated')
        ->assertSee('No visitors here yet')
        ->assertSee('Traffic periods · Logged-in players');
});

it('counts people where it counts people: a guest who played twice started once, and began signing up by email', function () {
    liveGame($this->draft);

    Livewire::withCookie((string) config('games.visitor_cookie.name'), str_repeat('a', 40))->test(PlayGame::class, ['slug' => $this->draft['game']->slug])
        ->call('init')->call('continue')->call('choose', 0)->call('next')
        ->call('signUpStarted', 'email')
        ->call('replay')->call('choose', 1)->call('next')
        ->assertSee('data-testid="game-step-full_result"', false);
    session()->flush();
    $this->actingAs($this->draft['admin']);

    $page = Livewire::test(GameAnalytics::class, ['record' => $this->draft['game']->id])
        ->assertSeeInOrder(['Game started', '1', '100.0% of visitors'])
        ->assertSeeInOrder(['Completed', '1', '100.0% of starters']);
    $funnel = collect($page->viewData('funnel'));

    expect($funnel->pluck('count', 'label')->all())->toBe(['Visitors' => '1', 'Game started' => '1', 'Completed' => '1', 'Sign-up begun' => '1', 'Goal reached' => '0', 'Account confirmed' => '0'])
        ->and($funnel->firstWhere('label', 'Sign-up begun')['hint'])->toBe('Email 1');
});

it('counts the emails of versions that ask for one', function () {
    $this->draft['flow'] = app(ChangeGameFlowAction::class)->create($this->draft['admin'], $this->draft['game'], 'Email first', GameFlowTemplate::EmailBeforeResult);
    liveGame($this->draft);

    Livewire::withCookie((string) config('games.visitor_cookie.name'), str_repeat('d', 40))->test(PlayGame::class, ['slug' => $this->draft['game']->slug])
        ->call('init')->call('continue')->call('choose', 0)->call('next')->call('continue')
        ->set('email', 'guest@example.test')->call('submitEmail')
        ->assertSee('data-testid="game-step-full_result"', false);
    session()->flush();
    $this->actingAs($this->draft['admin']);

    Livewire::test(GameAnalytics::class, ['record' => $this->draft['game']->id])
        ->assertSeeInOrder(['Email captured', '1', '100.0%']);
});

it('counts each person who finished a version asking for an email once, however many such versions they finished', function () {
    $resultFirst = $this->draft['flow'];
    $this->draft['flow'] = app(ChangeGameFlowAction::class)->create($this->draft['admin'], $this->draft['game'], 'Email first', GameFlowTemplate::EmailBeforeResult);
    $first = liveGame($this->draft);
    $second = app(PublishGameVersionAction::class)->handle($this->draft['admin'], $this->draft['game']->fresh(), $this->draft['set']->id, $this->draft['flow']->id);
    $finished = fn (GameVersion $version, string $guest): GameAttempt => GameAttempt::factory()->completed()->create(['version_id' => $version->id, 'visitor_hash' => hash('sha256', $guest)]);
    // One guest finished both versions that ask for an email, and left one email.
    $finished($first, 'guest-a');
    $emailed = $finished($second, 'guest-a');
    GameEmailCapture::query()->forceCreate(['attempt_id' => $emailed->id, 'game_id' => $this->draft['game']->id, 'email' => 'guest@example.test', 'captured_at' => now()]);
    $this->actingAs($this->draft['admin']);
    $captured = fn (): array => collect(Livewire::test(GameAnalytics::class, ['record' => $this->draft['game']->id])->viewData('funnel'))->firstWhere('label', 'Email captured');

    expect($captured())->toMatchArray(['count' => '1', 'rate' => '100.0%', 'rateOf' => 'of finishers asked']);

    // A version asking for none: its finishers are never asked; another guest finished one version that asks, and counts once.
    $third = app(PublishGameVersionAction::class)->handle($this->draft['admin'], $this->draft['game']->fresh(), $this->draft['set']->id, $resultFirst->id);
    $finished($third, 'guest-a');
    $finished($third, 'guest-c');
    $finished($second, 'guest-b');

    expect($captured())->toMatchArray(['count' => '1', 'rate' => '50.0%']);
});

it('narrows the figures to one version, and to the window asked for', function () {
    $version = liveGame($this->draft);
    $cookie = (string) config('games.visitor_cookie.name');

    $this->travelTo(now()->subDays(20));
    Livewire::withCookie($cookie, str_repeat('c', 40))->test(PlayGame::class, ['slug' => $this->draft['game']->slug])
        ->call('init')->call('continue');
    session()->flush();
    $this->travelBack();

    // V2, without traffic, with another guest's attempt started now.
    $second = app(PublishGameVersionAction::class)->handle($this->draft['admin'], $this->draft['game']->fresh(), $this->draft['set']->id, $this->draft['flow']->id);
    GameAttempt::factory()->create(['version_id' => $second->id]);

    $this->actingAs($this->draft['admin']);
    $started = fn ($page): string => collect($page->viewData('kpis'))->firstWhere('label', 'Game started')['value'];

    $page = Livewire::test(GameAnalytics::class, ['record' => $this->draft['game']->id]);
    expect($started($page))->toBe('2');

    $page->set('version', $version->id)
        ->assertSet('version', (string) $version->id);
    expect($started($page))->toBe('1');

    $page->set('period', '7d')
        ->assertSee('No visitors here yet')
        ->set('version', 0)
        ->assertDontSee('No visitors here yet');
    expect($started($page))->toBe('1');
});

it('names what each rate of the logged-in funnel is a share of: playing again is of the players who started', function () {
    $version = liveGame($this->draft);
    $player = User::factory()->create();
    GameAttempt::factory()->count(2)->create(['version_id' => $version->id, 'audience' => GameAudience::Authenticated, 'user_id' => $player->id, 'visitor_hash' => null]);

    $this->actingAs($this->draft['admin']);

    $page = Livewire::test(GameAnalytics::class, ['record' => $this->draft['game']->id])
        ->set('audience', 'authenticated')
        ->assertSeeHtml('<span class="rg-admin-table__meta">of Game started</span>')
        // Completed above counts players, and its note the plays.
        ->assertSee('0.0% of starters · 0 of 2 plays completed')
        ->assertSee('The Completed note above counts plays too, replays included.');
    $funnel = collect($page->viewData('funnel'))->keyBy('label');

    expect($funnel['Played again'])->toMatchArray(['count' => '1', 'rate' => '100.0%', 'rateOf' => 'of Game started'])
        ->and($funnel['Shared result']['rateOf'])->toBe('of Completed');
});

it('is only for those who manage games', function () {
    liveGame($this->draft);

    $this->actingAs(User::factory()->create())
        ->get(GameResource::getUrl('analytics', ['record' => $this->draft['game']]))
        ->assertForbidden();
});

it('leaves the emails out for a version chosen that asks for none, though another version does', function () {
    $emailFirst = app(ChangeGameFlowAction::class)->create($this->draft['admin'], $this->draft['game'], 'Email first', GameFlowTemplate::EmailBeforeResult);
    app(PublishGameVersionAction::class)->handle($this->draft['admin'], $this->draft['game'], $this->draft['set']->id, $emailFirst->id);
    $version = liveGame($this->draft);

    Livewire::withCookie((string) config('games.visitor_cookie.name'), str_repeat('e', 40))->test(PlayGame::class, ['slug' => $this->draft['game']->slug])
        ->call('init')->call('continue')->call('choose', 0)->call('next')
        ->assertSee('data-testid="game-step-full_result"', false);
    session()->flush();
    $this->actingAs($this->draft['admin']);

    $page = Livewire::test(GameAnalytics::class, ['record' => $this->draft['game']->id])
        ->set('version', $version->id)
        ->assertSet('version', (string) $version->id)
        ->assertDontSee('Email captured');

    expect(collect($page->viewData('funnel'))->pluck('label'))->not->toContain('Email captured');
});

it('follows the guests the result sends on to a post: who went, voted on it and rated it, each a share of the step it follows', function () {
    $group = RatingGroup::factory()->create(['key' => 'cuteness']);
    $option = RatingOption::factory()->for($group, 'group')->create();
    replaceFlowSteps($this->draft['flow'], [GameFlowStepType::Landing, GameFlowStepType::Game, GameFlowStepType::FullResult], settings: ['full_result' => ['onward' => 'post', 'ask_groups' => 'cuteness']]);
    liveGame($this->draft);
    $cookie = (string) config('games.visitor_cookie.name');

    // Forty days ago, in the 30 days before the ones shown, guest e went on to the post.
    $this->travelTo(now()->subDays(40));
    guestAtResult($this->draft, str_repeat('e', 40))->call('leave', 'step');
    session()->flush();
    $this->travelBack();

    // Guests a, b and c go on to the post, guest d stays.
    foreach (['a', 'b', 'c'] as $guest) {
        guestAtResult($this->draft, str_repeat($guest, 40))->call('leave', 'step');
        session()->flush();
    }

    guestAtResult($this->draft, str_repeat('d', 40));
    session()->flush();
    $post = Post::query()->findOrFail(GameRoundOption::query()->where('position', 0)->sole()->post_id);
    // On the post, the sign-in dialog opens for a and b; a signs up there and confirms.
    foreach (['a', 'b'] as $guest) {
        signInFormOpened(str_repeat($guest, 40), $post->id);
    }

    [$memberA, $memberB] = User::factory()->count(2)->create()->all();
    AccountAttribution::factory()->confirmed()->create([
        'user_id' => $memberA->id,
        'credited_attempt_id' => GameAttempt::query()->where('visitor_hash', hash('sha256', str_repeat('a', 40)))->sole()->id,
        'signup_place' => AccountSignupPlace::Post,
    ]);
    // Signed up, a votes on the post and rates it, b only votes. Signed in from here on: no guest plays after it.
    Livewire::withCookie($cookie, str_repeat('a', 40))->actingAs($memberA)->test(PostVoting::class, ['postId' => $post->id])->call('vote', 'up');
    Livewire::withCookie($cookie, str_repeat('a', 40))->actingAs($memberA)->test(RatingVoting::class, ['post' => $post, 'groupKey' => 'cuteness'])->call('vote', $option->id);
    Livewire::withCookie($cookie, str_repeat('b', 40))->actingAs($memberB)->test(PostVoting::class, ['postId' => $post->id])->call('vote', 'up');
    $this->actingAs($this->draft['admin']);

    $funnel = collect(Livewire::test(GameAnalytics::class, ['record' => $this->draft['game']->id])->viewData('funnel'))->keyBy('label');

    expect($funnel->keys()->all())->toBe(['Visitors', 'Game started', 'Completed', 'Went on to the post', 'Saw the sign-in form there', 'Signed up there', 'Confirmed that account', 'Voted on the post', 'Rated the post', 'Sign-up begun', 'Goal reached', 'Account confirmed'])
        ->and($funnel['Completed']['count'])->toBe('4')
        // Going on is of the people who finished; what came of it, of the people it sent there.
        ->and($funnel['Went on to the post'])->toMatchArray(['count' => '3', 'rate' => '75.0%', 'rateOf' => 'of Completed', 'hint' => 'Followed the result’s way on, or a Go to Post step, to the post'])
        // The way to an account there, each step of the one before it.
        ->and($funnel['Saw the sign-in form there'])->toMatchArray(['count' => '2', 'rate' => '66.7%', 'rateOf' => 'of Went on to the post', 'hint' => 'The sign-in or sign-up dialog opened on that post within 30 minutes, usually on trying to vote'])
        ->and($funnel['Signed up there'])->toMatchArray(['count' => '1', 'rate' => '50.0%', 'rateOf' => 'of Saw the sign-in form there', 'hint' => 'Made a new account starting from that post within 30 minutes'])
        ->and($funnel['Confirmed that account'])->toMatchArray(['count' => '1', 'rate' => '100.0%', 'rateOf' => 'of Signed up there', 'hint' => 'Confirmed the email, or signed up with Google or Facebook, within 7 days'])
        ->and($funnel['Voted on the post'])->toMatchArray(['count' => '2', 'rate' => '66.7%', 'rateOf' => 'of Went on to the post', 'hint' => 'Voted up or down on that post within 30 minutes; a vote needs an account'])
        ->and($funnel['Rated the post'])->toMatchArray(['count' => '1', 'rate' => '33.3%', 'rateOf' => 'of Went on to the post', 'hint' => 'Answered the rating the step asked for, within 30 minutes'])
        // Three went on, against the one of the 30 days before, who saw no form.
        ->and($funnel['Went on to the post']['change'])->toBe(['text' => '▲ 200% vs previous period', 'tone' => 'good'])
        ->and($funnel['Saw the sign-in form there']['change'])->toBe(['text' => 'No earlier data', 'tone' => null]);
});

it('shows a row only for the ways on the versions in view offer the audience', function () {
    // V1's result goes on to a post that asks for no rating; V2 ends with Go to Feed, for anonymous visitors only.
    replaceFlowSteps($this->draft['flow'], [GameFlowStepType::Landing, GameFlowStepType::Game, GameFlowStepType::FullResult], settings: ['full_result' => ['onward' => 'post']]);
    liveGame($this->draft);
    $feedFlow = GameFlow::factory()->create(['game_id' => $this->draft['game']->id]);
    replaceFlowSteps($feedFlow, [GameFlowStepType::Landing, GameFlowStepType::Game, GameFlowStepType::FullResult, GameFlowStepType::Feed]);
    $feedFlow->steps()->where('type', GameFlowStepType::Feed->value)->update(['audience' => 'anonymous']);
    $second = app(PublishGameVersionAction::class)->handle($this->draft['admin'], $this->draft['game']->fresh(), $this->draft['set']->id, $feedFlow->id);
    $cookie = (string) config('games.visitor_cookie.name');

    // Guests f and g are given V2 and go on to the feed; once signed up, f votes three times there.
    app(ApplyGameTrafficAction::class)->handle($this->draft['admin'], $this->draft['game'], GameAudience::Anonymous, [$second->id => 100]);

    foreach (['f', 'g'] as $guest) {
        guestAtResult($this->draft, str_repeat($guest, 40))->call('continue')->call('leave', 'step');
        session()->flush();
    }

    $member = User::factory()->create();

    foreach (Post::factory()->published()->count(3)->create() as $post) {
        Livewire::withCookie($cookie, str_repeat('f', 40))->actingAs($member)->test(PostVoting::class, ['postId' => $post->id])->call('vote', 'up');
    }

    $this->actingAs($this->draft['admin']);
    $funnel = fn ($page) => collect($page->viewData('funnel'))->keyBy('label');

    $page = Livewire::test(GameAnalytics::class, ['record' => $this->draft['game']->id]);
    expect($funnel($page)->keys()->all())->toBe(['Visitors', 'Game started', 'Completed', 'Went on to the post', 'Saw the sign-in form there', 'Signed up there', 'Confirmed that account', 'Voted on the post', 'Went on to the feed', 'Voted in the feed', 'Sign-up begun', 'Goal reached', 'Account confirmed'])
        ->and($funnel($page)['Completed']['count'])->toBe('2')
        // Nobody went on to the post; both went on to the feed, and one voted three times there.
        ->and($funnel($page)['Went on to the post'])->toMatchArray(['count' => '0', 'rate' => '0.0%', 'rateOf' => 'of Completed', 'hint' => 'Followed the result’s way on, or a Go to Post step, to the post'])
        ->and($funnel($page)['Voted on the post'])->toMatchArray(['count' => '0', 'rate' => '—', 'rateOf' => 'of Went on to the post', 'hint' => 'Voted up or down on that post within 30 minutes; a vote needs an account'])
        ->and($funnel($page)['Went on to the feed'])->toMatchArray(['count' => '2', 'rate' => '100.0%', 'rateOf' => 'of Completed', 'hint' => 'Followed the result’s way on, or a Go to Feed step, to the feed'])
        ->and($funnel($page)['Voted in the feed'])->toMatchArray(['count' => '1', 'rate' => '50.0%', 'rateOf' => 'of Went on to the feed', 'hint' => 'Three votes in the feed within 30 minutes']);

    // One version chosen: its own way on alone.
    $page->set('version', $second->id);
    expect($funnel($page)->keys()->all())->toBe(['Visitors', 'Game started', 'Completed', 'Went on to the feed', 'Voted in the feed', 'Sign-up begun', 'Goal reached', 'Account confirmed']);

    // Logged-in players are never sent to the feed, and need no account to vote.
    $page->set('version', 0)->set('audience', 'authenticated');
    expect($funnel($page)->keys()->all())->toBe(['Players', 'Game started', 'Completed', 'Shared result', 'Went on to the post', 'Voted on the post', 'Played again'])
        ->and($funnel($page)['Voted on the post']['hint'])->toBe('Voted up or down on that post within 30 minutes');
});

it('keeps a guest who signed in on the way with the guests, so a way on offered only to logged-in players gets their row too', function () {
    RatingGroup::factory()->create(['key' => 'cuteness']);
    replaceFlowSteps($this->draft['flow'], [GameFlowStepType::Landing, GameFlowStepType::Game, GameFlowStepType::FullResult, GameFlowStepType::Registration, GameFlowStepType::GoToPost], settings: ['full_result' => ['signup' => 'step'], 'go_to_post' => ['ask_groups' => 'cuteness']]);
    $this->draft['flow']->steps()->where('type', GameFlowStepType::GoToPost->value)->update(['audience' => 'authenticated']);
    liveGame($this->draft);
    $cookie = (string) config('games.visitor_cookie.name');

    // A guest plays to Registration and signs in there: their play stays a guest's, and the step for players sends them to the post.
    guestAtResult($this->draft, str_repeat('s', 40))->call('continue')->assertSee('data-testid="game-step-registration"', false);
    $user = User::factory()->create();
    $this->actingAs($user);
    app(ClaimGuestGameAttemptsAction::class)->handle($user, GameSessionAttempts::all(session()->driver()), GameAccountOutcome::SignedIn);
    Livewire::withCookie($cookie, str_repeat('s', 40))->test(PlayGame::class, ['slug' => $this->draft['game']->slug])
        ->assertSee('data-testid="game-step-go_to_post"', false)
        ->call('leave', 'step');
    session()->flush();
    $this->actingAs($this->draft['admin']);
    $funnel = fn ($page) => collect($page->viewData('funnel'))->keyBy('label');

    $page = Livewire::test(GameAnalytics::class, ['record' => $this->draft['game']->id]);
    expect($funnel($page)->keys()->all())->toBe(['Visitors', 'Game started', 'Completed', 'Went on to the post', 'Saw the sign-in form there', 'Signed up there', 'Confirmed that account', 'Voted on the post', 'Rated the post', 'Sign-up begun', 'Goal reached', 'Account confirmed'])
        ->and($funnel($page)['Went on to the post'])->toMatchArray(['count' => '1', 'rate' => '100.0%', 'rateOf' => 'of Completed']);

    // The players' own funnel offers the step too, though no player of theirs went yet.
    $page->set('audience', 'authenticated');
    expect($funnel($page)->keys()->all())->toBe(['Players', 'Game started', 'Completed', 'Shared result', 'Went on to the post', 'Voted on the post', 'Rated the post', 'Played again'])
        ->and($funnel($page)['Went on to the post']['count'])->toBe('0');
});

it('follows the guests sent on to a page or the next game, each a share of the people who finished', function () {
    Category::factory()->create(['slug' => 'cats']);
    $next = publishableGameDraft(1);
    liveGame($next);
    // V1 ends with Go to Page, V2 with Next Game.
    replaceFlowSteps($this->draft['flow'], [GameFlowStepType::Landing, GameFlowStepType::Game, GameFlowStepType::FullResult, GameFlowStepType::GoToPage], settings: ['go_to_page' => ['destination' => 'category', 'category' => 'cats']]);
    liveGame($this->draft);
    $nextFlow = GameFlow::factory()->create(['game_id' => $this->draft['game']->id]);
    replaceFlowSteps($nextFlow, [GameFlowStepType::Landing, GameFlowStepType::Game, GameFlowStepType::FullResult, GameFlowStepType::NextGame], $next['game']);
    $second = app(PublishGameVersionAction::class)->handle($this->draft['admin'], $this->draft['game']->fresh(), $this->draft['set']->id, $nextFlow->id);

    // Guest p goes on to the page from V1; then, given V2, guests n and o go on to the next game and guest q stays.
    guestAtResult($this->draft, str_repeat('p', 40))->call('continue')->call('leave', 'step');
    session()->flush();
    app(ApplyGameTrafficAction::class)->handle($this->draft['admin'], $this->draft['game'], GameAudience::Anonymous, [$second->id => 100]);

    foreach (['n', 'o'] as $guest) {
        guestAtResult($this->draft, str_repeat($guest, 40))->call('continue')->call('leave', 'next');
        session()->flush();
    }

    guestAtResult($this->draft, str_repeat('q', 40));
    session()->flush();
    $this->actingAs($this->draft['admin']);

    $funnel = collect(Livewire::test(GameAnalytics::class, ['record' => $this->draft['game']->id])->viewData('funnel'))->keyBy('label');

    expect($funnel->keys()->all())->toBe(['Visitors', 'Game started', 'Completed', 'Went on to a page', 'Went on to the next game', 'Sign-up begun', 'Goal reached', 'Account confirmed'])
        ->and($funnel['Completed']['count'])->toBe('4')
        ->and($funnel['Went on to a page'])->toMatchArray(['count' => '1', 'rate' => '25.0%', 'rateOf' => 'of Completed', 'hint' => 'Followed the result’s way on, or a Go to Page step, to a category, a tag, a page or another site'])
        ->and($funnel['Went on to the next game'])->toMatchArray(['count' => '2', 'rate' => '50.0%', 'rateOf' => 'of Completed', 'hint' => 'Followed the Next Game step to the game it suggests']);
});

it('measures Rated the post against the people asked to rate, while another version shown sends to a post without asking', function () {
    $group = RatingGroup::factory()->create(['key' => 'cuteness']);
    $option = RatingOption::factory()->for($group, 'group')->create();
    // V1's result goes on to a post and asks for a rating there; V2's goes on to one and asks for none.
    replaceFlowSteps($this->draft['flow'], [GameFlowStepType::Landing, GameFlowStepType::Game, GameFlowStepType::FullResult], settings: ['full_result' => ['onward' => 'post', 'ask_groups' => 'cuteness']]);
    $first = liveGame($this->draft);
    $plainFlow = GameFlow::factory()->create(['game_id' => $this->draft['game']->id]);
    replaceFlowSteps($plainFlow, [GameFlowStepType::Landing, GameFlowStepType::Game, GameFlowStepType::FullResult], settings: ['full_result' => ['onward' => 'post']]);
    $second = app(PublishGameVersionAction::class)->handle($this->draft['admin'], $this->draft['game']->fresh(), $this->draft['set']->id, $plainFlow->id);

    // Guests a and b go on to the post from V1, then guest c from V2; once signed up, a rates it.
    foreach (['a', 'b'] as $guest) {
        guestAtResult($this->draft, str_repeat($guest, 40))->call('leave', 'step');
        session()->flush();
    }

    app(ApplyGameTrafficAction::class)->handle($this->draft['admin'], $this->draft['game'], GameAudience::Anonymous, [$second->id => 100]);
    guestAtResult($this->draft, str_repeat('c', 40))->call('leave', 'step');
    session()->flush();
    $post = Post::query()->findOrFail(GameRoundOption::query()->where('position', 0)->sole()->post_id);
    Livewire::withCookie((string) config('games.visitor_cookie.name'), str_repeat('a', 40))->actingAs(User::factory()->create())
        ->test(RatingVoting::class, ['post' => $post, 'groupKey' => 'cuteness'])->call('vote', $option->id);
    $this->actingAs($this->draft['admin']);
    $funnel = fn ($page) => collect($page->viewData('funnel'))->keyBy('label');

    // Every version: of the two V1 asked, not of the three both sent to the post.
    $page = Livewire::test(GameAnalytics::class, ['record' => $this->draft['game']->id]);
    expect($funnel($page)['Went on to the post']['count'])->toBe('3')
        ->and($funnel($page)['Rated the post'])->toMatchArray(['count' => '1', 'rate' => '50.0%', 'rateOf' => 'of people asked to rate', 'hint' => 'Answered the rating the step asked for, within 30 minutes, of the people sent to a post by a version that asks']);

    // V1 alone: every version shown asks, so it is of the people sent to the post.
    $page->set('version', $first->id);
    expect($funnel($page)['Rated the post'])->toMatchArray(['count' => '1', 'rate' => '50.0%', 'rateOf' => 'of Went on to the post', 'hint' => 'Answered the rating the step asked for, within 30 minutes']);

    // V2 alone asks for none.
    $page->set('version', $second->id);
    expect($funnel($page)->keys()->all())->not->toContain('Rated the post');
});

it('compares the versions on paid traffic and shows every visitor by where they came from', function () {
    $first = liveGame($this->draft);
    $second = app(PublishGameVersionAction::class)->handle($this->draft['admin'], $this->draft['game']->fresh(), $this->draft['set']->id, $this->draft['flow']->id);
    $network = TrafficSource::factory()->create(['name' => 'PopAds main']);
    $banner = TrafficSource::factory()->create(['name' => 'Cat blog', 'type' => 'placement', 'place_site' => 'catblog.example']);
    $visits = function (int $count, array $state): void {
        TrafficVisit::factory()->count($count)->create(['game_id' => $this->draft['game']->id, 'opened_at' => now(), ...$state]);
    };

    // V1: 400 new visitors from the network and 5 from the banner, 40 accounts; V2: 400, 12 — a gap that is not chance.
    $visits(40, ['source_id' => $network->id, 'version_id' => $first->id, 'started_at' => now(), 'completed_at' => now(), 'goal_at' => now(), 'account_at' => now(), 'spend' => '0.5', 'value_real' => '0.5']);
    $visits(360, ['source_id' => $network->id, 'version_id' => $first->id, 'spend' => '0.01']);
    $visits(12, ['source_id' => $network->id, 'version_id' => $second->id, 'account_at' => now(), 'spend' => '0.5', 'value_real' => '0.5', 'device' => TrafficDevice::Desktop]);
    $visits(388, ['source_id' => $network->id, 'version_id' => $second->id, 'spend' => '0.01', 'device' => TrafficDevice::Desktop]);
    $visits(5, ['channel' => TrafficChannel::Placement, 'source_id' => $banner->id, 'click_hash' => null, 'version_id' => $first->id]);
    TrafficVisit::factory()->unpaid(TrafficChannel::Search)->count(3)->create(['game_id' => $this->draft['game']->id, 'opened_at' => now(), 'version_id' => $first->id]);
    // A visit of the window before moves the value against it.
    $visits(1, ['source_id' => $network->id, 'version_id' => $first->id, 'arrived_at' => now()->subDays(40), 'network_day' => now()->subDays(40)->toDateString(), 'value_real' => '0.5']);

    $this->actingAs($this->draft['admin']);

    $page = Livewire::test(GameAnalytics::class, ['record' => $this->draft['game']->id])
        ->assertSee('Versions on paid traffic')
        ->assertSee('V1 brings more accounts')
        ->assertSee('9.9% of new visitors end up a confirmed account on V1, against 3.0% on V2')
        ->assertSeeInOrder(['V1 · '.$this->draft['flow']->name, 'Ahead', '405', '40'])
        ->assertSee('Traffic sources')
        ->assertSeeInOrder(['Ad networks', '1 source', 'PopAds main', 'Banners &amp; links', 'Cat blog', 'catblog.example', 'Unpaid', 'Search', 'All visitors', '808'], false);

    // One network only: the banner's visitors leave the comparison; one device only: the desktop visitors leave the sources.
    $page->call('$set', 'paidSource', $banner->id)
        ->assertDontSee('V1 brings more accounts')
        ->set('sourcesDevice', 'mobile')
        ->assertSeeInOrder(['All visitors', '408']);

    expect(collect($page->viewData('sources')['groups'])->pluck('name')->all())->toBe(['Ad networks', 'Banners & links', 'Unpaid']);

    // Days only the network sent paid traffic in: the banner stays chosen — in the menu, the address and the card, with nobody to show — rather than every paid source read under its name.
    $day = now()->subDays(40)->toDateString();
    $page->call('applyRange', $day, $day)
        ->assertSet('paidSource', (string) $banner->id)
        ->assertSee('No paid visitor reached a version in this period.')
        ->assertViewHas('paid', fn (?array $paid): bool => $paid !== null
            && $paid['source'] === (string) $banner->id
            && array_column($paid['sources'], 'label', 'value') === ['0' => 'All paid sources', (string) $banner->id => 'Cat blog', (string) $network->id => 'PopAds main']
            && $paid['rows'] === []
            && str_starts_with($paid['note'], 'New visitors from Cat blog'));

    // A source there is no such is every paid source, in the address too.
    $page->call('$set', 'paidSource', '999999')->assertSet('paidSource', '0')->assertViewHas('paid', fn (?array $paid): bool => $paid !== null && $paid['source'] === '0');
});

it('lists only the traffic periods the version chosen had a share in, with every share but that version’s figures alone', function () {
    $game = $this->draft['game'];
    $periods = fn () => GameTrafficPeriod::query()->where('game_id', $game->id)->where('audience', GameAudience::Anonymous->value)->orderByDesc('id')->get();
    // A period someone was assigned in is kept when the shares change; an empty one is changed in place.
    $assign = fn (string $guest, GameVersion $to): GameAssignment => GameAssignment::query()->forceCreate([
        'game_id' => $game->id, 'audience' => GameAudience::Anonymous, 'visitor_hash' => hash('sha256', $guest),
        'version_id' => $to->id, 'period_id' => $periods()->first()->id, 'assigned_at' => now(), 'last_seen_at' => now(),
    ]);
    $this->travelTo('2026-11-10 12:00:00');
    $v1 = liveGame($this->draft);
    $assign('guest-z', $v1);
    $this->travelTo('2026-11-17 12:00:00');
    $v2 = app(PublishGameVersionAction::class)->handle($this->draft['admin'], $game->fresh(), $this->draft['set']->id, $this->draft['flow']->id);
    app(ApplyGameTrafficAction::class)->handle($this->draft['admin'], $game, GameAudience::Anonymous, [$v1->id => 50, $v2->id => 50]);
    $assign('guest-a', $v1);
    $b = $assign('guest-b', $v2);
    $this->travelTo('2026-11-18 12:00:00');
    app(ApplyGameTrafficAction::class)->handle($this->draft['admin'], $game, GameAudience::Anonymous, [$v2->id => 100]);
    $c = $assign('guest-c', $v2);
    GameAttempt::factory()->create(['version_id' => $v2->id, 'assignment_id' => $b->id, 'visitor_hash' => $b->visitor_hash, 'status' => GameAttemptStatus::Completed, 'completed_at' => now()]);
    GameAttempt::factory()->create(['version_id' => $v2->id, 'assignment_id' => $c->id, 'visitor_hash' => $c->visitor_hash]);
    $this->actingAs($this->draft['admin']);

    $page = Livewire::test(GameAnalytics::class, ['record' => $game->id]);

    // Every version: every period, each with the visitors it sent to a version and all they started.
    expect($periods())->toHaveCount(3)
        ->and(collect($page->viewData('periodRows'))->map(fn (array $row): string => $row['visitors'].' | '.$row['figures'])->all())->toBe([
            '1 visitor | 1 started · 0 completed',
            '2 visitors | 1 started · 1 completed',
            '1 visitor | 0 started · 0 completed',
        ]);

    // V1 had no share in the newest period: only the two it had, with its own figures, and every share still there.
    $page->set('version', $v1->id)->assertSee('Traffic periods · Anonymous visitors · V1');
    expect(collect($page->viewData('periodRows'))->map(fn (array $row): string => $row['shares'].' | '.$row['visitors'].' | '.$row['figures'])->all())->toBe([
        'V1 50% · V2 50% | 1 visitor | V1: 0 started · 0 completed',
        'V1 100% | 1 visitor | V1: 0 started · 0 completed',
    ]);

    $page->set('version', $v2->id);
    expect(collect($page->viewData('periodRows'))->map(fn (array $row): string => $row['visitors'].' | '.$row['figures'])->all())->toBe([
        '1 visitor | V2: 1 started · 0 completed',
        '1 visitor | V2: 1 started · 1 completed',
    ]);

    // Logged-in players were only ever sent to V1.
    $page->set('audience', 'authenticated')
        ->assertSee('V2 had no share of logged-in players in any period yet.')
        ->assertDontSee('No periods yet for this group.');
});

it('counts the anonymous sign-ups from the game’s recorded visits, those never confirmed and still waiting too, of the version chosen', function () {
    $first = liveGame($this->draft);
    $second = app(PublishGameVersionAction::class)->handle($this->draft['admin'], $this->draft['game']->fresh(), $this->draft['set']->id, $this->draft['flow']->id);
    $visit = fn (GameVersion $version, array $state) => TrafficVisit::factory()->create(['game_id' => $this->draft['game']->id, 'version_id' => $version->id, 'opened_at' => now(), ...$state]);
    // V1: one confirmed, one signed up ten days ago and never confirmed, one still waiting, one who never signed up.
    $visit($first, ['signup_at' => now(), 'account_at' => now()]);
    $visit($first, ['arrived_at' => now()->subDays(10), 'network_day' => now()->subDays(10)->toDateString(), 'signup_at' => now()->subDays(10)]);
    $visit($first, ['signup_at' => now()->subDay()]);
    $visit($first, []);
    // V2: one confirmed. Before the window: not counted.
    $visit($second, ['signup_at' => now(), 'account_at' => now()]);
    $visit($first, ['arrived_at' => now()->subDays(40), 'network_day' => now()->subDays(40)->toDateString(), 'signup_at' => now()->subDays(40)]);

    $this->actingAs($this->draft['admin']);
    $figures = fn ($page): array => collect($page->viewData('signups')['figures'])->pluck('value', 'label')->all();

    $page = Livewire::test(GameAnalytics::class, ['record' => $this->draft['game']->id])
        ->assertSeeInOrder(['Sign-ups', 'Counted from the visits of the game Traffic sources recorded in this period', 'Signed up', '4', 'Of 5 recorded visits']);

    expect($figures($page))->toBe(['Signed up' => '4', 'Confirmed' => '2', 'Never confirmed' => '1', 'Still waiting' => '1'])
        // Recording is off now, though these visits were recorded: it says so, and where to switch it on.
        ->and($page->viewData('signups')['note'])->toContain('Record visits is off now')
        ->and($page->viewData('signups')['trafficUrl'])->toBe(TrafficSourcesPage::getUrl(['tab' => 'sources']))
        // The funnel keeps its own steps: its counts are the game's, these the visits'.
        ->and(collect($page->viewData('funnel'))->pluck('label')->all())->toBe(['Visitors', 'Game started', 'Completed', 'Sign-up begun', 'Goal reached', 'Account confirmed']);

    $page->set('version', $first->id)->assertSee('Counted from the visits of this version');
    expect($figures($page))->toBe(['Signed up' => '3', 'Confirmed' => '1', 'Never confirmed' => '1', 'Still waiting' => '1']);

    $page->set('version', $second->id);
    expect($figures($page))->toBe(['Signed up' => '1', 'Confirmed' => '1', 'Never confirmed' => '0', 'Still waiting' => '0']);

    // Logged-in players never sign up: their funnel is as it was, without the card.
    $page->set('audience', 'authenticated')->assertViewHas('signups', null)->assertDontSee('Counted from the visits');
});

it('shows dashes, never a nought, and where visits are recorded, while the game has no recorded visit', function () {
    liveGame($this->draft);
    $this->actingAs($this->draft['admin']);

    $page = Livewire::test(GameAnalytics::class, ['record' => $this->draft['game']->id])
        ->assertSee('Visits aren’t recorded, so there are no sign-ups to count. Switch on Record visits under Traffic sources → Sources')
        ->assertSee('Record visits');

    expect($page->viewData('signups'))->toMatchArray(['tracked' => false, 'trafficUrl' => TrafficSourcesPage::getUrl(['tab' => 'sources'])])
        ->and(collect($page->viewData('signups')['figures'])->pluck('value')->unique()->all())->toBe(['—']);

    // Recording, but nobody of this game came: still nothing to count, and it says why.
    TrafficSetting::current()->forceFill(['capture_enabled' => true])->save();
    TrafficVisit::factory()->create(['signup_at' => now()]);

    Livewire::test(GameAnalytics::class, ['record' => $this->draft['game']->id])
        ->assertSee('No visit of the game was recorded in this period')
        ->assertViewHas('signups', fn (array $signups): bool => $signups['tracked'] === false && $signups['figures'][0]['value'] === '—' && $signups['trafficUrl'] === null);
});

it('draws the filter bar as the reference does: period, versions, the audiences with their visitors, device, country, then the days compared and Step prices', function () {
    $this->travelTo('2026-11-18 12:00:00');
    liveGame($this->draft);

    $this->actingAs($this->draft['admin'])
        ->get(GameResource::getUrl('analytics', ['record' => $this->draft['game']]))
        ->assertOk()
        ->assertSeeInOrder([
            'data-testid="game-analytics-filters"',
            'Last 30 days', '20 Oct — 18 Nov 2026',
            'Versions: <span x-text="triggers[selected]">All</span>',
            'Anonymous visitors', 'Logged-in players',
            'Device: <span x-text="triggers[selected]">All</span>',
            'Country: <span x-text="triggers[selected]">All</span>',
            '20 Oct — 18 Nov 2026 · compared with 20 Sep — 19 Oct 2026',
            'Step prices',
        ], false)
        // The picker's presets beside the calendar of its custom range, in one panel: the range is taken the moment its end is picked.
        ->assertSeeInOrder(['Last 7 days', 'Last 30 days', 'Last 90 days', 'All time', 'Custom range'])
        ->assertSeeInOrder(['id="rg-admin-games-analytics-period-panel"', 'x-on:rg-admin-range-chosen="takeRange($event.detail)"', 'rg-admin-date-range rg-admin-date-range--inline', "from: '2026-10-20'", "to: '2026-11-18'"], false)
        ->assertDontSee('id="rg-admin-games-analytics-period-from"', false)
        ->assertDontSee('rg-admin-period__foot', false)
        ->assertDontSee('type="date"', false);

    // The range starts from the very days the trigger names: whole days, today's included, as Traffic sources counts them.
    Livewire::test(GameAnalytics::class, ['record' => $this->draft['game']->id])
        ->assertViewHas('periodPicker', fn (array $picker): bool => $picker['from'] === '2026-10-20' && $picker['to'] === '2026-11-18' && $picker['dates'] === '20 Oct — 18 Nov 2026');
});

it('compares a range only with days still kept, and says so when the days before are gone', function () {
    $this->travelTo('2026-11-18 12:00:00');
    liveGame($this->draft);
    $this->actingAs($this->draft['admin']);
    $kept = min((int) config('games.retention_days.events'), (int) config('traffic.retention_days.visits'));
    // A range of 30 days starting 20 days after the oldest kept day: the 30 days before it reach past what is kept.
    $from = now()->startOfDay()->subDays($kept - 21)->toDateString();
    $to = now()->startOfDay()->subDays($kept - 50)->toDateString();

    Livewire::withQueryParams(['from' => $from, 'to' => $to])->test(GameAnalytics::class, ['record' => $this->draft['game']->id])
        ->assertViewHas('datesText', fn (string $text): bool => str_ends_with($text, 'the days before are no longer kept, nothing to compare with'))
        ->assertDontSee('vs previous period');
});

it('reads the period from the address — a preset or a range of days — and the last 30 days for anything it cannot read', function () {
    $this->travelTo('2026-11-18 12:00:00');
    $version = liveGame($this->draft);
    GameAttempt::factory()->create(['version_id' => $version->id, 'started_at' => '2026-11-05 10:00:00', 'last_activity_at' => '2026-11-05 10:00:00']);
    GameAttempt::factory()->create(['version_id' => $version->id, 'started_at' => '2026-10-25 10:00:00', 'last_activity_at' => '2026-10-25 10:00:00']);
    $this->actingAs($this->draft['admin']);
    $open = fn (array $query) => Livewire::withQueryParams($query)->test(GameAnalytics::class, ['record' => $this->draft['game']->id]);
    $started = fn ($page): string => collect($page->viewData('kpis'))->firstWhere('label', 'Game started')['value'];

    // Whole days, compared with as many days right before them.
    $page = $open(['from' => '2026-11-01', 'to' => '2026-11-10'])
        ->assertSet('from', '2026-11-01')
        ->assertSet('to', '2026-11-10')
        ->assertViewHas('datesText', '01 Nov — 10 Nov 2026 · compared with 22 Oct — 31 Oct 2026')
        ->assertViewHas('periodPicker', fn (array $picker): bool => $picker['value'] === 'custom' && $picker['label'] === 'Custom range' && $picker['dates'] === '01 Nov — 10 Nov 2026');
    expect($started($page))->toBe('1')
        ->and(collect($page->viewData('kpis'))->firstWhere('label', 'Game started')['change']['text'])->toBe('Same as previous period');

    // Reversed, past today, before the oldest day kept, no such day, not a day: the preset in the address, else the default.
    foreach ([['2026-11-10', '2026-11-01'], ['2026-11-01', '2026-11-19'], ['2025-01-01', '2025-01-31'], ['2026-02-30', '2026-03-02'], ['soon', '2026-11-01'], ['2026-11-01', '']] as [$from, $to]) {
        $open(['period' => '7d', 'from' => $from, 'to' => $to])->assertSet('period', '7d')->assertSet('from', '')->assertSet('to', '');
    }

    $open(['period' => '365d'])->assertSet('period', '30d')->assertViewHas('periodPicker', fn (array $picker): bool => $picker['label'] === 'Last 30 days');
    // All time names no days, so the calendar beside it shows no range that would read as one chosen.
    $open(['period' => 'all'])->assertViewHas('datesText', 'All time · nothing earlier to compare with')
        ->assertViewHas('periodPicker', fn (array $picker): bool => $picker['dates'] === null && $picker['from'] === '' && $picker['to'] === '')
        ->assertSeeInOrder(['rg-admin-date-range rg-admin-date-range--inline', "from: ''", "to: ''"], false);

    // A forged update is read the same way; the picker's own calls too.
    $page->set('to', '2027-01-01')->assertSet('period', '30d')->assertSet('from', '')->assertSet('to', '')
        ->call('applyRange', '2026-11-03', '2026-11-18')->assertSet('from', '2026-11-03')->assertSet('to', '2026-11-18')
        ->call('applyRange', ['2026-11-03'], null)->assertSet('from', '')->assertSet('period', '30d')
        ->call('choosePeriod', '90d')->assertSet('period', '90d')
        ->call('choosePeriod', 'forever')->assertSet('period', '30d');
    expect($started($page))->toBe('2');
});

it('narrows every card but Traffic periods to the device and country chosen, says only plays on recorded visits count, and drops the sources card’s own device switch', function () {
    $version = liveGame($this->draft);
    $game = $this->draft['game'];
    $period = GameTrafficPeriod::query()->where('game_id', $game->id)->where('audience', GameAudience::Anonymous->value)->whereNull('ended_at')->sole();
    $network = TrafficSource::factory()->create(['name' => 'PopAds main']);
    // A German phone that played and signed up, a French desktop that only opened the game.
    $visit = fn (string $guest, TrafficDevice $device, string $country, array $state = []) => TrafficVisit::factory()->create([
        'game_id' => $game->id, 'version_id' => $version->id, 'source_id' => $network->id, 'visitor_hash' => hash('sha256', $guest),
        'device' => $device, 'country' => $country, 'opened_at' => now(), ...$state,
    ]);
    $german = $visit('guest-a', TrafficDevice::Mobile, 'DE', ['started_at' => now(), 'signup_at' => now(), 'account_at' => now()]);
    $visit('guest-b', TrafficDevice::Desktop, 'FR');

    foreach (['guest-a', 'guest-b', 'guest-c'] as $guest) {
        (new GameEvent)->forceFill(['game_id' => $game->id, 'version_id' => $version->id, 'audience' => GameAudience::Anonymous, 'visitor_hash' => hash('sha256', $guest), 'type' => GameEventType::View, 'occurred_at' => now()])->save();
    }

    // The phone and the desktop were both sent to the version in the same traffic period.
    foreach (['guest-a', 'guest-b'] as $guest) {
        GameAssignment::query()->forceCreate([
            'game_id' => $game->id, 'audience' => GameAudience::Anonymous, 'visitor_hash' => hash('sha256', $guest),
            'version_id' => $version->id, 'period_id' => $period->id, 'assigned_at' => now(), 'last_seen_at' => now(),
        ]);
    }

    GameAttempt::factory()->create(['version_id' => $version->id, 'visitor_hash' => hash('sha256', 'guest-a'), 'traffic_visit_id' => $german->id]);
    // A guest with no recorded visit, playing all the same.
    GameAttempt::factory()->create(['version_id' => $version->id, 'visitor_hash' => hash('sha256', 'guest-c')]);
    $this->actingAs($this->draft['admin']);
    $kpi = fn ($page, string $label): string => collect($page->viewData('kpis'))->firstWhere('label', $label)['value'];
    $signedUp = fn ($page): string => collect($page->viewData('signups')['figures'])->firstWhere('label', 'Signed up')['value'];
    $periodVisitors = fn ($page): array => collect($page->viewData('periodRows'))->pluck('visitors')->all();

    $page = Livewire::test(GameAnalytics::class, ['record' => $game->id])
        ->assertViewHas('visitNote', null)
        ->assertViewHas('sourcesSwitch', true)
        ->assertSee('data-testid="game-traffic-sources-device"', false)
        // The paid sources are a menu now, for twenty and more.
        ->assertSee('Source: <span x-text="triggers[selected]">All paid</span>', false);
    expect([$kpi($page, 'Visitors'), $kpi($page, 'Game started'), $signedUp($page)])->toBe(['3', '2', '1'])
        ->and($periodVisitors($page))->toBe(['2 visitors']);

    $page->set('device', 'mobile')
        ->assertSee('Only plays that came on a visit Traffic sources recorded are counted while a device or country is chosen.')
        ->assertViewHas('sourcesSwitch', false)
        ->assertViewHas('cardDevice', 'mobile')
        ->assertDontSee('game-traffic-sources-device')
        ->assertViewHas('audienceCounts', ['anonymous' => '1', 'authenticated' => '0'])
        ->assertViewHas('sources', fn (array $sources): bool => $sources['total']['opened'] === 1)
        ->assertViewHas('paid', fn (array $paid): bool => collect($paid['rows'])->pluck('newVisitors')->all() === ['1']);
    expect([$kpi($page, 'Visitors'), $kpi($page, 'Game started'), $signedUp($page)])->toBe(['1', '1', '1'])
        // Traffic periods reads each period whole, as Growth → Experiments does, and says so.
        ->and($periodVisitors($page))->toBe(['2 visitors'])
        ->and($page->viewData('periodsNote'))->toEndWith(' Each period counts all of its own visitors, whatever the days, device and country chosen above.');

    // A country, then the group it is in, then the price set's last group: every other country.
    $page->set('device', '')->set('country', 'FR');
    expect([$kpi($page, 'Visitors'), $kpi($page, 'Game started'), $signedUp($page)])->toBe(['1', '0', '0']);
    $page->set('country', 'group:t1');
    expect([$kpi($page, 'Visitors'), $kpi($page, 'Game started')])->toBe(['1', '1']);
    $page->set('country', 'group:rest');
    expect($kpi($page, 'Visitors'))->toBe('0');

    // A device or country it doesn't know is every one.
    $page->set('country', 'ZZ')->assertViewHas('country', '')->set('device', 'tablet')->assertViewHas('device', '')->assertViewHas('visitNote', null);
    expect($kpi($page, 'Visitors'))->toBe('3');

    // The choices are kept in the address, the card's own device under a name of its own.
    Livewire::withQueryParams(['device' => 'desktop', 'country' => 'group:t2', 'sources_device' => 'mobile'])->test(GameAnalytics::class, ['record' => $game->id])
        ->assertSet('device', 'desktop')
        ->assertSet('country', 'group:t2')
        ->assertSet('sourcesDevice', 'mobile')
        // The page's device rules the card while one is chosen.
        ->assertViewHas('cardDevice', 'desktop')
        ->assertViewHas('countryOptions', fn (array $options): bool => array_column($options, 'value') === ['', 'group:t1', 'group:t2', 'group:rest', 'FR', 'DE']);
});

it('breaks the figures down by device, country and country group too', function () {
    $version = liveGame($this->draft);
    $game = $this->draft['game'];

    foreach ([['guest-a', TrafficDevice::Mobile, 'DE'], ['guest-b', TrafficDevice::Desktop, 'FR'], ['guest-c', TrafficDevice::Desktop, null]] as [$guest, $device, $country]) {
        $visit = TrafficVisit::factory()->unpaid()->create(['game_id' => $game->id, 'version_id' => $version->id, 'visitor_hash' => hash('sha256', $guest), 'device' => $device, 'country' => $country, 'opened_at' => now()]);
        (new GameEvent)->forceFill(['game_id' => $game->id, 'version_id' => $version->id, 'audience' => GameAudience::Anonymous, 'visitor_hash' => hash('sha256', $guest), 'type' => GameEventType::View, 'occurred_at' => now()])->save();
        GameAttempt::factory()->create(['version_id' => $version->id, 'visitor_hash' => hash('sha256', $guest), 'traffic_visit_id' => $visit->id]);
    }

    $this->actingAs($this->draft['admin']);
    $rows = fn ($page): array => collect($page->viewData('breakdownRows')['rows'])->map(fn (array $row): string => $row['name'].' | '.$row['sub'].' | '.$row['cells'][0][0])->all();

    $page = Livewire::test(GameAnalytics::class, ['record' => $game->id])
        ->assertViewHas('breakdowns', ['version' => 'Version', 'flow' => 'Flow', 'set' => 'Content set', 'device' => 'Device', 'country' => 'Country', 'group' => 'Group'])
        ->set('breakdown', 'device')
        ->assertSee('Each play by the recorded visit it came on');
    expect($rows($page))->toBe(['Desktop |  | 2', 'Mobile | Tablets count as mobile | 1']);

    $page->set('breakdown', 'country');
    expect($rows($page))->toBe(['Unknown country | Every other country | 1', 'Germany | DE · Tier 1 | 1', 'France | FR · Tier 2 | 1']);

    $page->set('breakdown', 'group');
    expect($rows($page))->toBe(['Tier 1 | US, GB, CA, AU, DE, AT, CH, NL | 1', 'Tier 2 | ES, FR, IT, PL | 1', 'Every other country | Every country the other groups don’t name, and unknown ones | 1']);

    // Something it can't break the figures down by: by version.
    $page->set('breakdown', 'city')->assertViewHas('breakdown', 'version');
});

it('tells for each traffic period whether its versions can be compared yet, by the accounts the versions comparison asks for', function () {
    $game = $this->draft['game'];
    $current = fn (): GameTrafficPeriod => GameTrafficPeriod::query()->where('game_id', $game->id)->where('audience', GameAudience::Anonymous->value)->whereNull('ended_at')->sole();
    // Guests sent to a version in the current period, each with an attempt and a confirmed account.
    $accounts = function (GameVersion $version, int $count) use ($game, $current): void {
        foreach (range(1, $count) as $guest) {
            $hash = hash('sha256', $version->id.'-'.$guest.'-'.fake()->uuid());
            $assignment = GameAssignment::query()->forceCreate([
                'game_id' => $game->id, 'audience' => GameAudience::Anonymous, 'visitor_hash' => $hash,
                'version_id' => $version->id, 'period_id' => $current()->id, 'assigned_at' => now(), 'last_seen_at' => now(),
            ]);
            $attempt = GameAttempt::factory()->create(['version_id' => $version->id, 'assignment_id' => $assignment->id, 'visitor_hash' => $hash]);
            AccountAttribution::factory()->confirmed()->create(['credited_attempt_id' => $attempt->id]);
        }
    };
    $this->travelTo('2026-11-10 12:00:00');
    $v1 = liveGame($this->draft);
    $accounts($v1, 1);
    $this->travelTo('2026-11-12 12:00:00');
    $v2 = app(PublishGameVersionAction::class)->handle($this->draft['admin'], $game->fresh(), $this->draft['set']->id, $this->draft['flow']->id);
    app(ApplyGameTrafficAction::class)->handle($this->draft['admin'], $game, GameAudience::Anonymous, [$v1->id => 50, $v2->id => 50]);
    $accounts($v1, 10);
    $accounts($v2, 9);
    $this->actingAs($this->draft['admin']);
    $badges = fn ($page): array => collect($page->viewData('periodRows'))->map(fn (array $row): string => $row['visitors'].' · '.($row['badge']['label'] ?? 'none'))->all();

    $page = Livewire::test(GameAnalytics::class, ['record' => $game->id])
        ->assertSeeInOrder(['Traffic periods', '19 visitors', 'Too early', '1 visitor', 'One version']);
    expect($badges($page))->toBe(['19 visitors · Too early', '1 visitor · One version']);

    // The tenth account on V2.
    $accounts($v2, 1);
    expect($badges(Livewire::test(GameAnalytics::class, ['record' => $game->id])))->toBe(['20 visitors · Enough data', '1 visitor · One version'])
        // Of one version: its own visitors, and still whether the period's versions can be compared.
        ->and($badges(Livewire::test(GameAnalytics::class, ['record' => $game->id])->set('version', $v2->id)))->toBe(['10 visitors · Enough data']);

    // Logged-in players were only ever sent to V1.
    expect($badges(Livewire::test(GameAnalytics::class, ['record' => $game->id])->set('audience', 'authenticated')))->toBe(['0 visitors · One version']);
});
