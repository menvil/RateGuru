<?php

namespace Tests\Feature\Seeders;

use App\Actions\Games\StartGameAttemptAction;
use App\Enums\GameAudience;
use App\Models\AccountAttribution;
use App\Models\Game;
use App\Models\TrafficSetting;
use App\Models\TrafficVisit;
use App\Models\TrafficVisitPost;
use App\Models\UserActivityDay;
use App\Support\Games\Play\GameVisitor;
use Database\Seeders\AnalyticsDemoSeeder;
use Database\Seeders\GamesDemoSeeder;
use Illuminate\Support\Facades\Storage;

/** The site's visitors over eight days instead of two months, so they play in seconds. */
class ShortAnalyticsDemoSeeder extends AnalyticsDemoSeeder
{
    protected function days(): int
    {
        return 8;
    }
}

/**
 * Visitors to the whole site, made through the tracker's own actions: both
 * switches on, sessions entered on the feed and on posts from every kind of
 * source — tagged and not — that read, share, vote, comment, post and go on
 * to the game, new accounts confirmed and coming back, some activated — and
 * only once.
 */
beforeEach(function () {
    Storage::fake('public');
    demoGameWithMembers(40);
    // The game's own history, which GameAnalyticsDemoSeeder plays first.
    $game = Game::query()->where('slug', GamesDemoSeeder::SLUG)->sole();
    $hash = hash('sha256', 'an earlier player');
    app(StartGameAttemptAction::class)->handle($game, new GameVisitor(GameAudience::Anonymous, null, $hash, $hash), 'en');
});

it('records two months of the site’s visitors as capture records them, once', function () {
    (new ShortAnalyticsDemoSeeder)->__invoke();

    $visits = TrafficVisit::query()->whereNull('source_id')->get();
    $sources = $visits->groupBy('source_key')->map->count();
    $site = $visits->whereIn('landing_key', ['feed', 'posts.show']);

    expect(TrafficSetting::captureFlags())->toBe(['capture' => true, 'site' => true])
        ->and($site->where('landing_key', 'feed'))->not->toBeEmpty()
        ->and($site->where('landing_key', 'posts.show'))->not->toBeEmpty()
        // Every source of the mix, a tagged and an untagged visit from Reddit under one key.
        ->and($sources->keys()->all())->toContain('reddit:organic', 'google:organic', 'google:paid', 'facebook:organic', 'facebook:paid', 'email:email', 'direct', 'bing:organic')
        ->and($visits->where('source_key', 'reddit:organic')->whereNotNull('utm_campaign'))->not->toBeEmpty()
        ->and($visits->where('source_key', 'reddit:organic')->whereNull('utm_campaign'))->not->toBeEmpty()
        ->and($visits->where('utm_campaign', 'weekly-digest')->whereNotNull('utm_content'))->not->toBeEmpty()
        // The game reached from the feed: a visit of the session the feed began.
        ->and($visits->whereNotNull('game_id')->where('landing_key', 'feed')->filter(fn (TrafficVisit $visit): bool => $visit->entry_visit_id !== $visit->id))->not->toBeEmpty()
        ->and($visits->sum('votes'))->toBeGreaterThan(0)
        ->and($visits->sum('comments'))->toBeGreaterThan(0)
        ->and(TrafficVisitPost::query()->where('views', '>', 0)->exists())->toBeTrue()
        ->and(TrafficVisitPost::query()->whereNotNull('shared_at')->exists())->toBeTrue()
        ->and(TrafficVisitPost::query()->where('voted', true)->exists())->toBeTrue()
        ->and(AccountAttribution::query()->whereNotNull('confirmed_at')->exists())->toBeTrue()
        ->and(AccountAttribution::query()->whereNotNull('activated_at')->exists())->toBeTrue()
        ->and(UserActivityDay::query()->where('day_number', '>=', 1)->exists())->toBeTrue()
        // As on the site, nobody signs up without the form shown on the visit first.
        ->and($visits->whereNotNull('signup_at'))->not->toBeEmpty()
        ->and($visits->whereNotNull('signup_at')->whereNull('signup_form_at'))->toBeEmpty();

    $count = TrafficVisit::query()->count();
    (new ShortAnalyticsDemoSeeder)->__invoke();

    expect(TrafficVisit::query()->count())->toBe($count);
});
