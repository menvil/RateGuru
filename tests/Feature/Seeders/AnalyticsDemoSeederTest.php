<?php

namespace Tests\Feature\Seeders;

use App\Actions\Games\StartGameAttemptAction;
use App\Enums\GameAudience;
use App\Models\AccountAttribution;
use App\Models\Game;
use App\Models\GameAssignment;
use App\Models\GameAttempt;
use App\Models\GameTrafficPeriod;
use App\Models\GameVersion;
use App\Models\Post;
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
    $this->game = Game::query()->where('slug', GamesDemoSeeder::SLUG)->sole();
    // The game, its traffic split and the posts there before the eight days, as GameAnalyticsDemoSeeder and
    // DemoFillSeeder leave them — the post read most published in the middle of them.
    $before = now()->subDays(20)->startOfSecond();
    GameVersion::query()->where('game_id', $this->game->id)->update(['published_at' => $before]);
    GameTrafficPeriod::query()->where('game_id', $this->game->id)->update(['started_at' => $before]);
    Post::query()->update(['published_at' => $before]);
    Post::query()->orderByDesc('upvotes_count')->orderBy('id')->firstOrFail()->forceFill(['published_at' => now()->subDays(4)->startOfSecond()])->save();
    // The game's own history, which GameAnalyticsDemoSeeder plays first.
    $hash = hash('sha256', 'an earlier player');
    app(StartGameAttemptAction::class)->handle($this->game, new GameVisitor(GameAudience::Anonymous, null, $hash, $hash), 'en');
});

it('records two months of the site’s visitors as capture records them, once', function () {
    (new ShortAnalyticsDemoSeeder)->__invoke();

    $visits = TrafficVisit::query()->whereNull('source_id')->get();
    $sources = $visits->groupBy('source_key')->map->count();
    $site = $visits->whereIn('landing_key', ['feed', 'posts.show']);
    $published = Post::query()->get(['id', 'published_at'])->keyBy('id');

    expect(TrafficSetting::captureFlags())->toBe(['capture' => true, 'site' => true])
        ->and($site->where('landing_key', 'feed'))->not->toBeEmpty()
        ->and($site->where('landing_key', 'posts.show'))->not->toBeEmpty()
        // Every source of the mix, a tagged and an untagged visit from Reddit under one key.
        ->and($sources->keys()->all())->toContain('reddit:organic', 'google:organic', 'google:paid', 'facebook:organic', 'facebook:paid', 'email:email', 'direct', 'bing:organic')
        ->and($visits->where('source_key', 'reddit:organic')->whereNotNull('utm_campaign'))->not->toBeEmpty()
        ->and($visits->where('source_key', 'reddit:organic')->whereNull('utm_campaign'))->not->toBeEmpty()
        ->and($visits->where('utm_campaign', 'weekly-digest')->whereNotNull('utm_content'))->not->toBeEmpty()
        // The game reached from the feed: a visit of the session the same browser's feed visit began.
        ->and($visits->whereNotNull('game_id')->where('landing_key', 'feed')->filter(function (TrafficVisit $visit) use ($visits): bool {
            $entry = $visit->entry_visit_id === null || $visit->entry_visit_id === $visit->id ? null : $visits->firstWhere('id', $visit->entry_visit_id);

            return $entry?->landing_key === 'feed' && $entry->game_id === null && $entry->visitor_hash === $visit->visitor_hash;
        }))->not->toBeEmpty()
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
        ->and($visits->whereNotNull('signup_at')->whereNull('signup_form_at'))->toBeEmpty()
        // Only posts published by then are read.
        ->and(TrafficVisitPost::query()->get()->filter(fn (TrafficVisitPost $row): bool => $row->first_at->lessThan($published[$row->post_id]->published_at)))->toBeEmpty();

    $count = TrafficVisit::query()->count();
    (new ShortAnalyticsDemoSeeder)->__invoke();

    expect(TrafficVisit::query()->count())->toBe($count);
});

it('takes visitors on from the feed to the game only from the moment its traffic split began', function () {
    // The split, and the version it shares out, begun three days ago.
    $began = now()->subDays(3)->startOfSecond();
    GameVersion::query()->where('game_id', $this->game->id)->update(['published_at' => $began]);
    GameTrafficPeriod::query()->where('game_id', $this->game->id)->update(['started_at' => $began]);

    (new ShortAnalyticsDemoSeeder)->__invoke();

    $attempts = GameAttempt::query()->with('version:id,published_at')->get();

    expect(GameAssignment::query()->with('period:id,started_at')->get()->filter(fn (GameAssignment $assignment): bool => $assignment->assigned_at->lessThan($assignment->period->started_at)))->toBeEmpty()
        ->and($attempts->filter(fn (GameAttempt $attempt): bool => $attempt->started_at->lessThan($attempt->version->published_at)))->toBeEmpty()
        ->and(TrafficVisit::query()->whereNotNull('game_id')->where('arrived_at', '<', $began)->exists())->toBeFalse()
        // From then on, they play.
        ->and($attempts->filter(fn (GameAttempt $attempt): bool => $attempt->traffic_visit_id !== null))->not->toBeEmpty();
});
