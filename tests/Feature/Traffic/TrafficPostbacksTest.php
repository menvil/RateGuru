<?php

use App\Actions\Traffic\MarkTrafficVisitAction;
use App\Actions\Traffic\RecordAccountLoginAction;
use App\Actions\Traffic\ResendTrafficPostbackAction;
use App\Actions\Traffic\SendTestPostbackAction;
use App\Enums\TrafficEvent;
use App\Enums\TrafficPostbackOutcome;
use App\Enums\TrafficPostbackSkip;
use App\Enums\TrafficPostbackStatus;
use App\Enums\TrafficSourceStatus;
use App\Livewire\Games\PlayGame;
use App\Models\GameAttempt;
use App\Models\ProjectSettings;
use App\Models\TrafficConversion;
use App\Models\TrafficPostback;
use App\Models\TrafficPostbackAttempt;
use App\Models\TrafficPostbackRule;
use App\Models\TrafficPriceSet;
use App\Models\TrafficSource;
use App\Models\TrafficStepValue;
use App\Models\TrafficVisit;
use App\Models\TrafficVisitDetail;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    recordTraffic(capture: true, postbacks: true);
    bindFakeHostResolver(['pb.example.com' => ['93.184.216.40'], 'intranet.example.com' => ['10.0.0.5']]);
    Http::preventStrayRequests();
    $this->source = TrafficSource::factory()->create();
    $this->rule = TrafficPostbackRule::factory()->create(['source_id' => $this->source->id, 'event' => TrafficEvent::Started, 'url_template' => 'https://pb.example.com/c?id={click_id}&e={event}&v={value}&z={param:zone}', 'enabled' => true]);
});

/** A paid click of the source that carries the network's click ID. */
function paidClick(TrafficSource $source, array $attributes = []): TrafficVisit
{
    $visit = TrafficVisit::factory()->create(['source_id' => $source->id, 'zone_ref' => 'z77', 'opened_at' => now(), ...$attributes]);
    TrafficVisitDetail::query()->forceCreate(['visit_id' => $visit->id, 'click_id' => 'CLICK-1', 'landing_path' => '/', 'created_at' => now()]);

    return $visit;
}

it('turns a paid click’s step into one conversion and a postback waiting for each rule', function () {
    $visit = paidClick($this->source);

    expect(app(MarkTrafficVisitAction::class)->handle($visit->id, TrafficEvent::Started, now()->toImmutable()))->toBeTrue()
        ->and(app(MarkTrafficVisitAction::class)->handle($visit->id, TrafficEvent::Started, now()->toImmutable()))->toBeFalse();

    $conversion = TrafficConversion::query()->sole();
    $postback = TrafficPostback::query()->sole();

    expect($conversion->step)->toBe('started')
        ->and($conversion->postback_skip)->toBeNull()
        ->and($postback->status)->toBe(TrafficPostbackStatus::Pending)
        ->and($postback->rule_id)->toBe($this->rule->id)
        ->and($postback->url)->toBe('https://pb.example.com/c?id={click_id}&e=started&v=0&z=z77');
});

it('keeps the conversion of a known visitor without telling the network', function () {
    $visit = paidClick($this->source, ['known' => true]);

    app(MarkTrafficVisitAction::class)->handle($visit->id, TrafficEvent::Started, now()->toImmutable());

    expect(TrafficConversion::query()->sole()->postback_skip)->toBe(TrafficPostbackSkip::KnownVisitor)
        ->and(TrafficPostback::query()->count())->toBe(0);
});

it('queues nothing while postbacks are switched off', function () {
    recordTraffic(capture: true, postbacks: false);
    $visit = paidClick($this->source);

    app(MarkTrafficVisitAction::class)->handle($visit->id, TrafficEvent::Started, now()->toImmutable());

    expect(TrafficConversion::query()->count())->toBe(1)
        ->and(TrafficPostback::query()->count())->toBe(0);
});

it('undoes the conversion with the game write it came with', function () {
    $visit = paidClick($this->source);

    try {
        DB::transaction(function () use ($visit): void {
            app(MarkTrafficVisitAction::class)->handle($visit->id, TrafficEvent::Started, now()->toImmutable());

            throw new RuntimeException('The game write failed.');
        });
    } catch (RuntimeException) {
    }

    expect(TrafficConversion::query()->count())->toBe(0)
        ->and($visit->fresh()->started_at)->toBeNull();
});

it('tells the visit of a play each moment and the goal', function () {
    ProjectSettings::factory()->create();
    $draft = publishableGameDraft(2);
    $draft['flow']->forceFill(['goal' => 'completed'])->save();
    liveGame($draft);
    $visit = paidClick($this->source, ['game_id' => $draft['game']->id]);

    $play = Livewire::withCookie((string) config('games.visitor_cookie.name'), str_repeat('p', 40))
        ->test(PlayGame::class, ['slug' => $draft['game']->slug])->call('init')->call('continue');
    GameAttempt::query()->sole()->forceFill(['traffic_visit_id' => $visit->id])->save();
    $play->call('choose', 0)->call('next')->call('choose', 0)->call('next');

    $visit->refresh();

    expect($visit->halfway_at)->not->toBeNull()
        ->and($visit->completed_at)->not->toBeNull()
        ->and($visit->goal_at)->not->toBeNull()
        ->and($visit->rounds_answered)->toBe(2)
        ->and(TrafficConversion::query()->where('visit_id', $visit->id)->pluck('event')->map->value->sort()->values()->all())->toBe(['completed', 'goal', 'halfway'])
        ->and(TrafficConversion::query()->where('event', TrafficEvent::Halfway)->sole()->step)->toBe('round_1')
        ->and(TrafficConversion::query()->where('event', TrafficEvent::Goal)->sole()->goal)->toBe('completed');
});

it('sends the waiting postbacks with the click ID, and keeps what the network answered', function () {
    $visit = paidClick($this->source);
    app(MarkTrafficVisitAction::class)->handle($visit->id, TrafficEvent::Started, now()->toImmutable());
    Http::fake(['pb.example.com/*' => Http::response('OK', 200)]);

    $this->artisan('traffic:send-postbacks')->assertSuccessful();

    $postback = TrafficPostback::query()->sole();
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://pb.example.com/c?id=CLICK-1&e=started&v=0&z=z77');

    expect($postback->status)->toBe(TrafficPostbackStatus::Delivered)
        ->and($postback->outcome)->toBe(TrafficPostbackOutcome::Ok)
        ->and($postback->attempts)->toBe(1)
        ->and(TrafficPostbackAttempt::query()->count())->toBe(0);
});

it('tries a postback again when the network is down, and gives up at once when it refuses one', function () {
    $down = TrafficPostback::factory()->create(['source_id' => $this->source->id, 'rule_id' => $this->rule->id, 'url' => 'https://pb.example.com/down?id={click_id}', 'conversion_id' => TrafficConversion::factory()->create(['visit_id' => paidClick($this->source)->id])->id]);
    $refused = TrafficPostback::factory()->create(['source_id' => $this->source->id, 'rule_id' => $this->rule->id, 'url' => 'https://pb.example.com/refused?id={click_id}', 'conversion_id' => TrafficConversion::factory()->create(['visit_id' => paidClick($this->source)->id])->id]);
    Http::fake(['pb.example.com/down*' => Http::response('', 503), 'pb.example.com/refused*' => Http::response('no such click', 400)]);

    $this->artisan('traffic:send-postbacks')->assertSuccessful();

    expect($down->fresh()->status)->toBe(TrafficPostbackStatus::Pending)
        ->and($down->fresh()->run_after->diffInSeconds(now(), true))->toBeGreaterThan(55)
        ->and($refused->fresh()->status)->toBe(TrafficPostbackStatus::Failed)
        ->and($refused->fresh()->outcome)->toBe(TrafficPostbackOutcome::Rejected)
        ->and(TrafficPostbackAttempt::query()->count())->toBe(2);
});

it('never connects to a private address, and cancels what a paused source still had waiting', function () {
    $private = TrafficPostback::factory()->create(['source_id' => $this->source->id, 'rule_id' => $this->rule->id, 'url' => 'https://intranet.example.com/c?id={click_id}', 'conversion_id' => TrafficConversion::factory()->create(['visit_id' => paidClick($this->source)->id])->id]);
    $paused = TrafficSource::factory()->status(TrafficSourceStatus::Paused)->create();
    $pausedRule = TrafficPostbackRule::factory()->create(['source_id' => $paused->id, 'enabled' => true]);
    $waiting = TrafficPostback::factory()->create(['source_id' => $paused->id, 'rule_id' => $pausedRule->id, 'conversion_id' => TrafficConversion::factory()->create(['visit_id' => paidClick($paused)->id])->id]);
    Http::fake();

    $this->artisan('traffic:send-postbacks')->assertSuccessful();

    Http::assertNothingSent();
    expect($private->fresh()->outcome)->toBe(TrafficPostbackOutcome::BlockedUrl)
        ->and($private->fresh()->status)->toBe(TrafficPostbackStatus::Pending)
        ->and($waiting->fresh()->status)->toBe(TrafficPostbackStatus::Cancelled);
});

it('sends nothing while postbacks are switched off', function () {
    TrafficPostback::factory()->create(['source_id' => $this->source->id, 'rule_id' => $this->rule->id, 'conversion_id' => TrafficConversion::factory()->create(['visit_id' => paidClick($this->source)->id])->id]);
    recordTraffic(capture: true, postbacks: false);
    Http::fake();

    $this->artisan('traffic:send-postbacks')->assertSuccessful();

    Http::assertNothingSent();
});

it('sends a failed postback once more on request', function () {
    $failed = TrafficPostback::factory()->create(['source_id' => $this->source->id, 'rule_id' => $this->rule->id, 'url' => 'https://pb.example.com/c?id={click_id}', 'status' => TrafficPostbackStatus::Failed, 'attempts' => 6, 'conversion_id' => TrafficConversion::factory()->create(['visit_id' => paidClick($this->source)->id])->id]);
    Http::fake(['pb.example.com/*' => Http::response('OK', 200)]);

    $attempt = app(ResendTrafficPostbackAction::class)->handle(User::factory()->admin()->create(), $failed);

    expect($attempt->outcome)->toBe(TrafficPostbackOutcome::Ok)
        ->and($failed->fresh()->status)->toBe(TrafficPostbackStatus::Delivered)
        ->and($failed->fresh()->resent_at)->not->toBeNull()
        ->and($failed->fresh()->attempts)->toBe(7);
});

it('sends a test postback from an unsaved rule without writing anything', function () {
    Http::fake(['pb.example.com/*' => Http::response('OK', 200)]);

    $attempt = app(SendTestPostbackAction::class)->handle(User::factory()->admin()->create(), 'https://pb.example.com/t?id={click_id}&v={value}', TrafficEvent::Goal, '9', null);

    Http::assertSent(fn (Request $request): bool => preg_match('#^https://pb\.example\.com/t\?id=TEST-[0-9a-f]{8}&v=0\.01$#', $request->url()) === 1);
    expect($attempt->outcome)->toBe(TrafficPostbackOutcome::Ok)
        ->and($attempt->httpStatus)->toBe(200)
        ->and(TrafficPostback::query()->count())->toBe(0);
});

it('fails a PopAds postback whose answer names an error in its header, keeping what it said', function () {
    $this->source->forceFill(['response_rules' => ['rules' => [['outcome' => 'rejected', 'match' => 'header', 'text' => 'X-PopAds-EC']], 'otherwise' => 'ok']])->save();
    $postback = TrafficPostback::factory()->create(['source_id' => $this->source->id, 'rule_id' => $this->rule->id, 'url' => 'https://pb.example.com/c?id={click_id}', 'conversion_id' => TrafficConversion::factory()->create(['visit_id' => paidClick($this->source)->id])->id]);
    Http::fake(['pb.example.com/*' => Http::response('', 200, ['X-PopAds-EC' => '7'])]);

    $this->artisan('traffic:send-postbacks')->assertSuccessful();

    expect($postback->fresh())->status->toBe(TrafficPostbackStatus::Failed)->outcome->toBe(TrafficPostbackOutcome::Rejected)->response_excerpt->toBe('X-PopAds-EC: 7');
});

it('records a postback whose answer is a GIF pixel, rather than sending it again', function () {
    $postback = TrafficPostback::factory()->create(['source_id' => $this->source->id, 'rule_id' => $this->rule->id, 'url' => 'https://pb.example.com/c?id={click_id}', 'conversion_id' => TrafficConversion::factory()->create(['visit_id' => paidClick($this->source)->id])->id]);
    Http::fake(['pb.example.com/*' => Http::response(base64_decode('R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw=='), 200, ['Content-Type' => 'image/gif'])]);

    $this->artisan('traffic:send-postbacks')->assertSuccessful();

    expect($postback->fresh())->status->toBe(TrafficPostbackStatus::Delivered)->response_excerpt->toBe('[not text: 43 bytes]');
});

it('holds a resent postback as long as a send run’s claim, so the run leaves it alone', function () {
    $failed = TrafficPostback::factory()->create(['source_id' => $this->source->id, 'rule_id' => $this->rule->id, 'url' => 'https://pb.example.com/c?id={click_id}', 'status' => TrafficPostbackStatus::Failed, 'run_after' => now()->subDay(), 'attempts' => 1, 'conversion_id' => TrafficConversion::factory()->create(['visit_id' => paidClick($this->source)->id])->id]);
    Http::fake(function () use ($failed) {
        expect($failed->fresh())->status->toBe(TrafficPostbackStatus::Sending)
            ->and($failed->fresh()->run_after->greaterThan(now()->addSeconds(60)))->toBeTrue();

        return Http::response('OK', 200);
    });

    app(ResendTrafficPostbackAction::class)->handle(User::factory()->admin()->create(), $failed);

    expect($failed->fresh()->status)->toBe(TrafficPostbackStatus::Delivered);
});

it('goes on past a batch of postbacks that only expired, rather than waiting a minute for each batch', function () {
    $conversion = fn () => TrafficConversion::factory()->create(['visit_id' => paidClick($this->source)->id])->id;
    TrafficPostback::factory()->count(45)->create(['source_id' => $this->source->id, 'rule_id' => $this->rule->id, 'url' => 'https://pb.example.com/c?id={click_id}', 'run_after' => now()->subHours(2), 'expires_at' => now()->subHour(), 'conversion_id' => $conversion]);
    $live = TrafficPostback::factory()->create(['source_id' => $this->source->id, 'rule_id' => $this->rule->id, 'url' => 'https://pb.example.com/c?id={click_id}', 'run_after' => now()->subMinute(), 'conversion_id' => $conversion()]);
    Http::fake(['pb.example.com/*' => Http::response('OK', 200)]);

    $this->artisan('traffic:send-postbacks')->assertSuccessful();

    expect($live->fresh()->status)->toBe(TrafficPostbackStatus::Delivered)
        ->and(TrafficPostback::query()->where('status', TrafficPostbackStatus::Expired)->count())->toBe(45);
});

/** A paid click whose visitor started and finished the game, finishing worth $0.05 by hand. */
function finishedPaidClick(TrafficSource $source, array $attributes = []): TrafficVisit
{
    $value = TrafficStepValue::factory()->create(['step' => 'completed', 'value' => '0.05']);
    TrafficStepValue::factory()->create(['game_id' => $value->game_id, 'step' => 'started', 'value' => '0.02']);

    return paidClick($source, ['game_id' => $value->game_id, 'started_at' => now()->subHour(), 'completed_at' => now()->subHour(), ...$attributes]);
}

it('settles a paid visit 30 minutes after its last step, once, telling the best worth it reached', function () {
    $rule = TrafficPostbackRule::factory()->create(['source_id' => $this->source->id, 'event' => TrafficEvent::Settled, 'url_template' => 'https://pb.example.com/s?id={click_id}&e={event}&v={value}', 'enabled' => true]);
    $visit = finishedPaidClick($this->source, ['arrived_at' => now()->subHours(2), 'last_seen_at' => now()->subMinutes(31)]);

    $this->artisan('traffic:settle-visits')->assertSuccessful();
    $this->artisan('traffic:settle-visits')->assertSuccessful();

    $conversion = TrafficConversion::query()->where('event', TrafficEvent::Settled)->sole();

    expect($visit->fresh()->settled_at->toDateTimeString())->toBe($visit->last_seen_at->addMinutes(30)->toDateTimeString())
        ->and($conversion->step)->toBe('completed')
        ->and((string) $conversion->value)->toStartWith('0.05')
        ->and(TrafficPostback::query()->where('rule_id', $rule->id)->sole()->url)->toBe('https://pb.example.com/s?id={click_id}&e=settled&v=0.05');
});

it('waits while the visitor keeps going, and settles a day after the click however busy', function () {
    $busy = finishedPaidClick($this->source, ['arrived_at' => now()->subHour(), 'last_seen_at' => now()->subMinutes(40)]);
    $day = finishedPaidClick($this->source, ['arrived_at' => now()->subHours(25), 'last_seen_at' => now()->subMinute()]);

    // A step now: the visitor is still here.
    app(MarkTrafficVisitAction::class)->handle($busy->id, TrafficEvent::PostOpened, now()->toImmutable());
    // So is one answering rounds.
    app(MarkTrafficVisitAction::class)->answered($day->id, 3);
    $this->artisan('traffic:settle-visits')->assertSuccessful();

    expect($busy->fresh()->settled_at)->toBeNull()
        ->and($day->fresh()->settled_at->toDateTimeString())->toBe($day->arrived_at->addHours(24)->toDateTimeString());
});

it('settles a visit at once when its account is confirmed, with the account’s worth', function () {
    TrafficPostbackRule::factory()->create(['source_id' => $this->source->id, 'event' => TrafficEvent::Settled, 'url_template' => 'https://pb.example.com/s?id={click_id}&v={value}', 'enabled' => true]);
    $visit = paidClick($this->source);
    $at = now()->toImmutable();

    DB::transaction(fn () => app(MarkTrafficVisitAction::class)->handle($visit->id, TrafficEvent::Account, $at));

    $settled = TrafficConversion::query()->where('event', TrafficEvent::Settled)->sole();

    expect($visit->fresh()->settled_at->toDateTimeString())->toBe($at->toDateTimeString())
        ->and($settled->step)->toBe('account')
        ->and((string) $settled->value)->toStartWith('0.5');

    // Settled already: the run leaves it alone.
    $this->travel(2)->days();
    $this->artisan('traffic:settle-visits')->assertSuccessful();
    expect(TrafficConversion::query()->where('event', TrafficEvent::Settled)->count())->toBe(1);
});

it('tells a network what an account is worth at its source’s own price, in {value} and {value_total}', function () {
    TrafficPostbackRule::factory()->create(['source_id' => $this->source->id, 'event' => TrafficEvent::Settled, 'url_template' => 'https://pb.example.com/s?id={click_id}&v={value}&t={value_total}', 'enabled' => true]);
    $other = TrafficSource::factory()->create();
    TrafficPostbackRule::factory()->create(['source_id' => $other->id, 'event' => TrafficEvent::Settled, 'url_template' => 'https://pb.example.com/o?id={click_id}&v={value}&t={value_total}', 'enabled' => true]);
    TrafficPriceSet::factory()->create(['source_account_prices' => [$this->source->id => '0.9000']]);
    $own = paidClick($this->source);
    $site = paidClick($other, ['click_hash' => hash('sha256', 'other click')]);

    DB::transaction(fn () => app(MarkTrafficVisitAction::class)->handle($own->id, TrafficEvent::Account, now()->toImmutable()));
    DB::transaction(fn () => app(MarkTrafficVisitAction::class)->handle($site->id, TrafficEvent::Account, now()->toImmutable()));

    expect((string) TrafficConversion::query()->where('visit_id', $own->id)->where('event', TrafficEvent::Settled)->sole()->value)->toStartWith('0.9')
        ->and(TrafficPostback::query()->where('source_id', $this->source->id)->sole()->url)->toBe('https://pb.example.com/s?id={click_id}&v=0.9&t=0.9')
        ->and(TrafficPostback::query()->where('source_id', $other->id)->sole()->url)->toBe('https://pb.example.com/o?id={click_id}&v=0.5&t=0.5');
});

it('never tells a network of a sign-in to an existing account, nor of the visit settling after it, however it is priced', function () {
    TrafficPostbackRule::factory()->create(['source_id' => $this->source->id, 'event' => TrafficEvent::Settled, 'url_template' => 'https://pb.example.com/s?id={click_id}&v={value}', 'enabled' => true]);
    $priced = TrafficPriceSet::factory()->create(['login_price' => '0.4']);
    $visit = paidClick($this->source, ['price_set_id' => $priced->id, 'arrived_at' => now()->subHours(2), 'last_seen_at' => now()->subHour()]);

    app(RecordAccountLoginAction::class)->handle(User::factory()->create(), $visit->visitor_hash, now()->subHour()->toImmutable());

    // A sign-in is no event a network can be told of: no conversion, and the visit is worth its price.
    expect(TrafficConversion::query()->count())->toBe(0)
        ->and($visit->fresh()->value_real)->toBe('0.40000000');

    app(MarkTrafficVisitAction::class)->handle($visit->id, TrafficEvent::Started, now()->subMinutes(50)->toImmutable());
    $this->artisan('traffic:settle-visits')->assertSuccessful();
    $settled = TrafficConversion::query()->where('event', TrafficEvent::Settled)->sole();

    expect($settled->step)->toBe('login')
        ->and((string) $settled->value)->toStartWith('0.4')
        ->and($settled->postback_skip)->toBe(TrafficPostbackSkip::KnownVisitor)
        ->and(TrafficConversion::query()->where('event', TrafficEvent::Started)->sole()->postback_skip)->toBe(TrafficPostbackSkip::KnownVisitor)
        ->and(TrafficPostback::query()->count())->toBe(0);
});

it('tells the network only of settled visits that got as far as the rule asks', function () {
    TrafficPostbackRule::factory()->create(['source_id' => $this->source->id, 'event' => TrafficEvent::Settled, 'needs_event' => TrafficEvent::Completed, 'url_template' => 'https://pb.example.com/s?id={click_id}&v={value}', 'enabled' => true]);
    $opened = paidClick($this->source, ['arrived_at' => now()->subHours(2), 'last_seen_at' => now()->subHour()]);
    $finished = finishedPaidClick($this->source, ['arrived_at' => now()->subHours(2), 'last_seen_at' => now()->subHour()]);

    $this->artisan('traffic:settle-visits')->assertSuccessful();

    expect(TrafficConversion::query()->where('visit_id', $opened->id)->where('event', TrafficEvent::Settled)->sole()->postback_skip)->toBe(TrafficPostbackSkip::TooShallow)
        ->and(TrafficConversion::query()->where('visit_id', $finished->id)->where('event', TrafficEvent::Settled)->sole()->postback_skip)->toBeNull()
        ->and(TrafficPostback::query()->where('event', TrafficEvent::Settled)->count())->toBe(1);
});

it('settles no unpaid, unopened or long-missed visit, and nothing while recording is off', function () {
    $unpaid = TrafficVisit::factory()->unpaid()->create(['opened_at' => now(), 'arrived_at' => now()->subHours(2), 'last_seen_at' => now()->subHour()]);
    $unopened = paidClick($this->source, ['opened_at' => null, 'arrived_at' => now()->subHours(2), 'last_seen_at' => now()->subHour()]);
    $missed = paidClick($this->source, ['arrived_at' => now()->subDays(3), 'last_seen_at' => now()->subDays(3)]);
    $due = paidClick($this->source, ['arrived_at' => now()->subHours(2), 'last_seen_at' => now()->subHour()]);

    recordTraffic(capture: false, postbacks: true);
    $this->artisan('traffic:settle-visits')->assertSuccessful();
    expect($due->fresh()->settled_at)->toBeNull();

    recordTraffic(capture: true, postbacks: true);
    $this->artisan('traffic:settle-visits')->assertSuccessful();

    expect($due->fresh()->settled_at)->not->toBeNull()
        ->and($unpaid->fresh()->settled_at)->toBeNull()
        ->and($unopened->fresh()->settled_at)->toBeNull()
        ->and($missed->fresh()->settled_at)->toBeNull();
});

it('lets an account meet any floor, and a step above the floor meet it too', function () {
    TrafficPostbackRule::factory()->create(['source_id' => $this->source->id, 'event' => TrafficEvent::Settled, 'needs_event' => TrafficEvent::Started, 'url_template' => 'https://pb.example.com/s?id={click_id}&v={value}', 'enabled' => true]);
    // Signed up from a post before ever pressing Start.
    $account = paidClick($this->source);
    // Finished, its start never marked.
    $finished = paidClick($this->source, ['completed_at' => now()->subHour(), 'arrived_at' => now()->subHours(2), 'last_seen_at' => now()->subHour()]);

    DB::transaction(fn () => app(MarkTrafficVisitAction::class)->handle($account->id, TrafficEvent::Account, now()->toImmutable()));
    $this->artisan('traffic:settle-visits')->assertSuccessful();

    expect(TrafficConversion::query()->where('event', TrafficEvent::Settled)->pluck('postback_skip', 'visit_id')->all())->toBe([$account->id => null, $finished->id => null])
        ->and(TrafficPostback::query()->where('event', TrafficEvent::Settled)->count())->toBe(2);
});

it('counts a step as the visit seen only while a paid visit can still settle, and every round answered too', function () {
    $old = TrafficVisit::factory()->unpaid()->create(['opened_at' => now()->subDays(2), 'arrived_at' => now()->subDays(2), 'last_seen_at' => now()->subDays(2)]);
    $settled = paidClick($this->source, ['arrived_at' => now()->subHours(3), 'last_seen_at' => now()->subHours(3), 'settled_at' => now()->subHours(2)]);
    $playing = paidClick($this->source, ['arrived_at' => now()->subHour(), 'last_seen_at' => now()->subMinutes(20), 'rounds_answered' => 5]);

    // A return days later, a first post on a settled visit: neither visit is back in the half-hour windows.
    app(MarkTrafficVisitAction::class)->handle($old->id, TrafficEvent::Returned, now()->toImmutable());
    app(MarkTrafficVisitAction::class)->handle($settled->id, TrafficEvent::PostPublished, now()->toImmutable());
    // A replay's second round on a visit that answered five already.
    app(MarkTrafficVisitAction::class)->answered($playing->id, 2);

    expect($old->fresh()->last_seen_at->toDateTimeString())->toBe($old->last_seen_at->toDateTimeString())
        ->and($settled->fresh()->last_seen_at->toDateTimeString())->toBe($settled->last_seen_at->toDateTimeString())
        ->and($playing->fresh())->rounds_answered->toBe(5)
        ->and($playing->fresh()->last_seen_at->greaterThan(now()->subMinute()))->toBeTrue();
});
