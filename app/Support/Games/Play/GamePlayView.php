<?php

namespace App\Support\Games\Play;

use App\Enums\GameAnswerOutcome;
use App\Enums\GameAttemptStatus;
use App\Enums\GameFlowStepType;
use App\Enums\GameRoundMode;
use App\Enums\GameSignupMode;
use App\Enums\GameStatus;
use App\Enums\PostImageContext;
use App\Enums\ShareProvider;
use App\Models\Game;
use App\Models\GameAttempt;
use App\Models\MediaAsset;
use App\Queries\Games\GameTrafficQuery;
use App\Support\Games\Scoring\GameScorer;
use App\Support\Games\Snapshot\GameVersionSnapshot;
use App\Support\Games\Snapshot\SnapshotRound;
use App\Support\Games\Snapshot\SnapshotStep;
use App\Support\Media\PostImagePresenter;
use App\Support\Media\ResponsiveImage;
use App\Support\Settings\ProjectSettingsManager;
use App\Support\Sharing\ShareMetadata;
use App\Support\Sharing\ShareUrlBuilder;

/**
 * Everything the game page draws for the step on screen, in the visitor's
 * language: the round and its pictures, the reveal of a pick, the result and
 * its sharing, the texts of every other step with the player's numbers put
 * in. Nothing here decides anything — the attempt and the version's snapshot
 * already did.
 */
final readonly class GamePlayView
{
    /** Pictures of a pair sit side by side from the smallest screens up. */
    private const PAIR_SIZES = '(max-width: 639px) 50vw, 420px';

    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(
        public string $state,
        public string $pageTitle,
        public array $data,
    ) {}

    public static function unavailable(Game $game, string $reason): self
    {
        return new self('unavailable', $game->title, [
            'game' => self::gameData($game, 0, null),
            'reason' => $reason,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $reveal
     */
    public static function of(Game $game, int $versionId, GameVersionSnapshot $snapshot, string $stepKey, ?GameAttempt $attempt, ?array $reveal, GameVisitor $visitor, string $locale, ?Game $nextGame, bool $hasNext, bool $preview = false): self
    {
        $title = $snapshot->title->in($locale);
        $gameData = self::gameData($game, $versionId, $snapshot, $locale);
        $step = $snapshot->step($stepKey);

        // A post the version shows has left the site: nothing of it is shown.
        if (! $preview && app(GameTrafficQuery::class)->unplayableVersionIds([$versionId]) !== []) {
            return new self('unavailable', $title, ['game' => $gameData, 'reason' => 'unavailable']);
        }

        if ($step === null) {
            return new self('end', $title, [
                'game' => $gameData,
                'canReplay' => $preview || $game->status === GameStatus::Live,
            ]);
        }

        $result = $attempt !== null && $attempt->status === GameAttemptStatus::Completed ? self::result($game, $versionId, $snapshot, $attempt, $locale) : null;

        return new self('step', $title, [
            'game' => $gameData,
            'type' => $step->type->value,
            'texts' => self::texts($step, $locale, $result, $title),
            'settings' => $step->settings,
            'round' => $step->type === GameFlowStepType::Game ? self::round($snapshot, $attempt, $reveal, $locale) : null,
            'result' => $result,
            'signup' => $step->type === GameFlowStepType::FullResult && $visitor->isAnonymous()
                ? (GameSignupMode::tryFrom((string) $step->setting('signup')) ?? GameSignupMode::Inline) === GameSignupMode::Inline
                : false,
            'authenticated' => ! $visitor->isAnonymous(),
            'nextGame' => $nextGame === null ? null : ['title' => $nextGame->title, 'slug' => $nextGame->slug],
            'canReplay' => $preview || $game->status === GameStatus::Live,
            'stepKey' => $step->key,
            'hasNext' => $hasNext,
        ]);
    }

    /** @return array<string, mixed> */
    private static function gameData(Game $game, int $versionId, ?GameVersionSnapshot $snapshot, string $locale = 'en'): array
    {
        $settings = app(ProjectSettingsManager::class)->current();
        $coverId = $snapshot?->coverAssetId;
        $cover = $coverId !== null ? MediaAsset::query()->with('variants')->find($coverId) : null;

        return [
            'id' => (int) $game->id,
            'slug' => $game->slug,
            'title' => $snapshot?->title->in($locale) ?? $game->title,
            'description' => $snapshot?->description->in($locale) ?? (string) $game->description,
            'site' => $settings->siteName(),
            'cover' => self::image($cover, '(max-width: 767px) 100vw, 720px'),
            // The game's own link, shared: its cover, never a post's picture.
            'ogImage' => $cover !== null && ! $cover->trashed() ? app(PostImagePresenter::class)->openGraphAsset($cover) : null,
            'url' => route('games.play', ['slug' => $game->slug]),
            'versionId' => $versionId,
            'roundCount' => $snapshot?->roundCount() ?? 0,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $result
     * @return array<string, string>
     */
    private static function texts(SnapshotStep $step, string $locale, ?array $result, string $gameTitle): array
    {
        $values = [
            '{matches}' => (string) ($result['matches'] ?? 0),
            '{total}' => (string) ($result['scored'] ?? 0),
            '{score}' => (string) ($result['score'] ?? 0),
            '{max}' => (string) ($result['max'] ?? 0),
            '{profile}' => (string) ($result['profile']['name'] ?? ''),
            '{game}' => $gameTitle,
        ];
        $texts = [];

        foreach (array_keys($step->type->texts()) as $column) {
            $texts[$column] = strtr($step->text($column, $locale), $values);
        }

        return $texts;
    }

    /**
     * @param  array<string, mixed>|null  $reveal
     * @return array<string, mixed>
     */
    private static function round(GameVersionSnapshot $snapshot, ?GameAttempt $attempt, ?array $reveal, string $locale): array
    {
        $answers = $attempt === null ? collect() : $attempt->answers->keyBy('round_index');
        $index = $reveal !== null ? (int) $reveal['round'] : ($attempt === null ? 0 : $attempt->rounds_answered);
        $index = min($index, max(0, $snapshot->roundCount() - 1));
        $round = $snapshot->round($index);
        $next = $snapshot->round($index + 1);
        $assetIds = array_values(array_filter([
            ...array_map(fn ($option) => $option->assetId, $round === null ? [] : $round->options),
            ...array_map(fn ($option) => $option->assetId, $next === null ? [] : $next->options),
        ]));
        $assets = MediaAsset::query()->with('variants')->whereIn('id', $assetIds)->get()->keyBy('id');
        $progress = [];

        foreach ($snapshot->rounds as $position => $item) {
            $answer = $answers->get($position);
            $progress[] = match (true) {
                $answer !== null => $answer->outcome->value,
                $position === $index => 'current',
                default => 'todo',
            };
        }

        return [
            'index' => $index,
            'total' => $snapshot->roundCount(),
            'question' => $round?->question->in($locale) ?? '',
            'description' => $round?->description->in($locale) ?? '',
            'scored' => $round === null || $round->scored,
            'options' => $round === null ? [] : array_map(fn ($option): array => [
                'position' => $option->position,
                'label' => $option->label->in($locale),
                'image' => self::image($assets->get((int) $option->assetId), self::PAIR_SIZES),
            ], $round->options),
            'preload' => $next === null ? [] : array_values(array_filter(array_map(fn ($option): ?string => self::image($assets->get((int) $option->assetId), self::PAIR_SIZES)?->src, $next->options))),
            'progress' => $progress,
            'reveal' => $reveal === null || $round === null ? null : self::reveal($round, $reveal, $locale),
        ];
    }

    /**
     * @param  array<string, mixed>  $reveal
     * @return array<string, mixed>
     */
    private static function reveal(SnapshotRound $round, array $reveal, string $locale): array
    {
        $site = app(ProjectSettingsManager::class)->current()->siteName();
        $outcome = GameAnswerOutcome::from((string) $reveal['outcome']);
        $crowd = $round->mode === GameRoundMode::Crowd && $round->scored;
        $shares = $crowd && $round->showCrowd
            ? array_combine(array_map(fn ($option) => $option->position, $round->options), array_map(fn ($option) => $option->displayShare(), $round->options))
            : [];

        $picked = $round->option((int) $reveal['position'])?->displayShare();
        $other = $round->option(1 - (int) $reveal['position'])?->displayShare();

        $verdict = match (true) {
            $outcome === GameAnswerOutcome::Neutral => __('games.play.verdict_neutral'),
            $crowd && $outcome === GameAnswerOutcome::Correct => __('games.play.verdict_match'),
            $crowd => __('games.play.verdict_differ'),
            $outcome === GameAnswerOutcome::Correct => __('games.play.verdict_correct'),
            default => __('games.play.verdict_incorrect'),
        };

        return [
            'position' => (int) $reveal['position'],
            'answer' => $outcome === GameAnswerOutcome::Neutral ? null : $round->answer,
            'outcome' => $outcome->value,
            'shares' => $shares,
            'crowd' => $crowd,
            'verdict' => $verdict,
            'detail' => $shares !== [] && $picked !== null && $other !== null
                ? __('games.play.shares_detail', ['picked' => self::percent($picked), 'other' => self::percent($other), 'site' => $site])
                : '',
            'message' => $round->reveal->in($locale),
            'last' => (bool) $reveal['last'],
        ];
    }

    /** @return array<string, mixed> */
    private static function result(Game $game, int $versionId, GameVersionSnapshot $snapshot, GameAttempt $attempt, string $locale): array
    {
        $outcomes = $attempt->answers->sortBy('round_index')->pluck('outcome')->values()->all();
        $result = GameScorer::result($snapshot, $outcomes);
        $profile = $attempt->profile_key !== null ? $snapshot->profile($attempt->profile_key) : $result->profile;
        $title = $snapshot->title->in($locale);
        $site = app(ProjectSettingsManager::class)->current()->siteName();
        $versionNumber = (int) $game->versions()->whereKey($versionId)->value('number');
        $shareUrl = $profile !== null && $versionNumber > 0 ? route('games.result', ['slug' => $game->slug, 'version' => $versionNumber, 'profile' => $profile->key]) : route('games.play', ['slug' => $game->slug]);
        $shareText = $profile !== null
            ? strtr($profile->shareText->in($locale) !== '' ? $profile->shareText->in($locale) : __('games.play.share_default'), ['{profile}' => $profile->name->in($locale), '{game}' => $title])
            : $title;
        $shareImage = $profile?->shareAssetId !== null ? MediaAsset::query()->with('variants')->find($profile->shareAssetId) : null;

        return [
            'matches' => (int) ($attempt->matches ?? $result->matches),
            'scored' => $result->scored,
            'score' => (int) ($attempt->score ?? $result->score),
            'max' => (int) $attempt->max_score,
            'points' => $snapshot->pointsPerRound,
            'agreement' => $result->agreement(),
            'showAgreement' => $snapshot->showAgreement,
            'byCrowd' => $snapshot->scoresByCrowdOnly(),
            'profile' => $profile === null ? null : [
                'key' => $profile->key,
                'name' => $profile->name->in($locale),
                'description' => $profile->description->in($locale),
                'icon' => $profile->icon,
                'image' => self::image($shareImage, '(max-width: 767px) 100vw, 640px'),
            ],
            'share' => [
                'url' => $shareUrl,
                'text' => $shareText,
                'links' => self::shareLinks($game, $versionNumber, $profile?->key, $shareUrl, $shareText, $title, $site),
            ],
        ];
    }

    /** @return list<array{provider: string, label: string, url: string|null}> */
    private static function shareLinks(Game $game, int $versionNumber, ?string $profileKey, string $url, string $text, string $title, string $site): array
    {
        $links = [];
        $metadata = new ShareMetadata(title: $title, shareText: $text, description: $text, url: $url, imageUrl: null, siteName: $site);

        foreach ([ShareProvider::X, ShareProvider::Facebook, ShareProvider::WhatsApp, ShareProvider::Telegram, ShareProvider::Email] as $provider) {
            if (! config("share.providers.{$provider->value}.enabled", true)) {
                continue;
            }

            $links[] = [
                'provider' => $provider->value,
                'label' => __('games.play.share_'.$provider->value),
                'url' => $provider->sharesThroughRedirect() && $profileKey !== null && $versionNumber > 0
                    ? route('games.share', ['slug' => $game->slug, 'version' => $versionNumber, 'profile' => $profileKey, 'provider' => $provider->value])
                    : app(ShareUrlBuilder::class)->build($provider, $metadata),
            ];
        }

        return $links;
    }

    private static function image(?MediaAsset $asset, string $sizes): ?ResponsiveImage
    {
        if ($asset === null || $asset->trashed()) {
            return null;
        }

        $image = app(PostImagePresenter::class)->responsiveAsset($asset, PostImageContext::Feed);

        return $image === null ? null : new ResponsiveImage($image->src, $image->srcset, $image->srcset !== null ? $sizes : null, $image->width, $image->height);
    }

    private static function percent(float $share): string
    {
        return rtrim(rtrim(number_format($share, 1), '0'), '.');
    }
}
