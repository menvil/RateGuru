<?php

use App\Actions\Traffic\MarkTrafficVisitAction;
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
use App\Models\TrafficSource;
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
