<?php

namespace Database\Seeders;

use App\Actions\Games\ApplyGameTrafficAction;
use App\Actions\Games\ChangeGameStatusAction;
use App\Actions\Games\CreateGameAction;
use App\Actions\Games\PublishGameVersionAction;
use App\Actions\Games\SaveContentSetAction;
use App\Data\Games\ContentSetDraft;
use App\Data\Games\GameGeneralData;
use App\Enums\GameAudience;
use App\Enums\GameOptionSource;
use App\Enums\GameRoundMode;
use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\Post;
use App\Models\User;
use App\Support\Games\Drafts\DraftImageState;
use App\Support\Games\Results\ResultProfileCoverage;
use App\Support\Games\Versus\CrowdComparison;
use App\Support\Games\Versus\CrowdSide;
use App\Support\Games\Versus\ImageState;
use App\Support\Settings\ProjectSettingsManager;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A live Versus game to look at locally — “Trust Your Taste”: five rounds of
 * two of the site's own published posts each, built, published and given all
 * the traffic the way an administrator would, with games switched on.
 *
 * Its rounds need posts the crowd has decided between: at least
 * games.crowd.min_votes votes each and different upvote shares, apart by
 * games.crowd.min_gap points where that asks for a gap; each pair is the
 * least and the best liked left, so it is never close. The compact demo seed has
 * too few votes for that, so run the large dataset first:
 *
 *     php artisan db:seed --class=DemoFillSeeder
 *     php artisan db:seed --class=GamesDemoSeeder
 *
 * Safe to run again: once the game exists, it is left as it is. Local and
 * testing environments only.
 */
class GamesDemoSeeder extends Seeder
{
    public const SLUG = 'trust-your-taste';

    private const ROUNDS = 5;

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        if (Game::query()->where('slug', self::SLUG)->exists()) {
            $this->command?->info('The demo game is already there; nothing was changed.');

            return;
        }

        $admin = User::query()->where('email', 'admin@rateguru.test')->first();

        if ($admin === null) {
            $this->command?->warn('Seed the demo accounts first (php artisan db:seed); no game was made.');

            return;
        }

        $pairs = $this->pairs();

        if (count($pairs) < self::ROUNDS) {
            $this->command?->warn('Too few posts with enough votes for '.self::ROUNDS.' rounds; run DemoFillSeeder first. No game was made.');

            return;
        }

        // All of it or none of it: a run that fails half way leaves no half-made game behind.
        DB::transaction(function () use ($admin, $pairs): void {
            $game = app(CreateGameAction::class)->handle($admin, new GameGeneralData(
                title: 'Trust Your Taste',
                internalName: 'Trust Your Taste (demo)',
                slug: self::SLUG,
                description: 'Pick the picture you think people liked more. Five pairs, one result.',
            ));
            $set = $game->contentSets()->sole();
            $flow = $game->flows()->sole();

            app(SaveContentSetAction::class)->handle($admin, $set, $this->draft($set->crowd_min_votes, $set->crowd_min_gap, $pairs), (int) $set->lock_version);
            $version = app(PublishGameVersionAction::class)->handle($admin, $game->fresh(), (int) $set->id, (int) $flow->id);

            foreach (GameAudience::cases() as $audience) {
                app(ApplyGameTrafficAction::class)->handle($admin, $game->fresh(), $audience, [(int) $version->id => 100]);
            }

            app(ChangeGameStatusAction::class)->handle($admin, $game->fresh(), GameStatus::Live);

            // Created from the defaults when the database has no settings row
            // yet, so the game is reachable at the address this run prints.
            $settings = app(ProjectSettingsManager::class)->lockedRow();
            $settings->forceFill(['feature_flags' => [...($settings->feature_flags ?? []), 'allow_games' => true]])->save();
        });

        app(ProjectSettingsManager::class)->flush();

        $this->command?->info('Trust Your Taste is live at /games/'.self::SLUG.'.');
    }

    /**
     * Pairs of published posts with a picture a game can show — ready, in
     * its feed size — that the crowd has clearly decided between: the least
     * liked with the most liked, and inwards.
     *
     * @return list<array{0: Post, 1: Post}>
     */
    private function pairs(): array
    {
        $minVotes = (int) config('games.crowd.min_votes');
        $minGap = (int) config('games.crowd.min_gap');
        /** @var Collection<int, Post> $posts */
        $posts = Post::query()->published()->whereNotNull('image_asset_id')->with('imageAsset.variants')->orderBy('id')->get()
            ->filter(fn (Post $post): bool => DraftImageState::of($post->imageAsset) === ImageState::Ready)
            ->filter(fn (Post $post): bool => $post->upvotes_count + $post->downvotes_count >= $minVotes)
            ->sortBy(fn (Post $post): float => $post->upvotes_count / ($post->upvotes_count + $post->downvotes_count))
            ->values();
        $pairs = [];

        for ($low = 0, $high = $posts->count() - 1; $low < $high && count($pairs) < self::ROUNDS; $low++, $high--) {
            $a = $posts[$low];
            $b = $posts[$high];
            $crowd = CrowdComparison::of(
                new CrowdSide((int) $a->id, (int) $a->upvotes_count, (int) $a->downvotes_count),
                new CrowdSide((int) $b->id, (int) $b->upvotes_count, (int) $b->downvotes_count),
                $minVotes,
                $minGap,
            );

            if ($crowd->isMajority()) {
                // The better liked post on either side, round by round.
                $pairs[] = count($pairs) % 2 === 0 ? [$a, $b] : [$b, $a];
            }
        }

        return $pairs;
    }

    /** @param  list<array{0: Post, 1: Post}>  $pairs */
    private function draft(int $minVotes, int $minGap, array $pairs): ContentSetDraft
    {
        $rounds = [];

        foreach (array_slice($pairs, 0, self::ROUNDS) as $index => [$a, $b]) {
            $option = fn (Post $post): array => ['source' => GameOptionSource::Post->value, 'post_id' => (int) $post->id, 'asset_id' => null, 'label' => '', 'is_correct' => false];
            $rounds[] = [
                'id' => null,
                'key' => 'r'.($index + 1),
                'internal_name' => 'Round '.($index + 1),
                'question' => 'Which one got more upvotes?',
                'description' => '',
                'reveal_message' => '',
                'mode' => GameRoundMode::Crowd->value,
                'unscored' => false,
                'show_crowd' => true,
                'options' => [$option($a), $option($b)],
            ];
        }

        $names = ResultProfileCoverage::suggestedNames(self::ROUNDS);
        $profiles = [];

        foreach (ResultProfileCoverage::suggestedRanges(self::ROUNDS) as $index => $range) {
            $suggested = ResultProfileCoverage::SUGGESTED[$names[$index]];
            $profiles[] = [
                'id' => null,
                'key' => Str::slug($suggested['name']),
                'name' => $suggested['name'],
                'description' => $suggested['description'],
                'min' => $range['min'],
                'max' => $range['max'],
                'icon' => $suggested['icon'],
                'share_text' => 'I got {profile} in {game}.',
                'share_asset_id' => null,
            ];
        }

        return new ContentSetDraft(
            scoring: ['points_per_round' => 1, 'crowd_min_votes' => $minVotes, 'crowd_min_gap' => $minGap],
            rounds: $rounds,
            profiles: $profiles,
        );
    }
}
