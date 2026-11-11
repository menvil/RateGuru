<?php

namespace App\Support\Games\Snapshot;

use App\Enums\GameMechanic;
use App\Enums\GameRoundMode;
use App\Support\Games\Flow\FlowStepDraft;
use App\Support\Games\Results\ResultProfileCoverage;
use App\Support\Games\Results\ResultProfileRange;
use InvalidArgumentException;

/**
 * Everything a published version plays from, read from its stored snapshot:
 * the game's texts and cover, scoring, rounds with the crowd's votes as they
 * stood, result profiles and the flow. A stored snapshot is never rewritten;
 * a later shape of it gets a new schema number and a reader of its own.
 */
final readonly class GameVersionSnapshot
{
    public const SCHEMA = 1;

    /**
     * @param  list<SnapshotRound>  $rounds
     * @param  list<SnapshotProfile>  $profiles
     * @param  list<SnapshotStep>  $steps
     * @param  list<string>  $locales
     */
    public function __construct(
        public int $gameId,
        public string $slug,
        public GameMechanic $mechanic,
        public LocalizedText $title,
        public LocalizedText $description,
        public ?int $coverAssetId,
        public int $pointsPerRound,
        public int $crowdMinVotes,
        public int $crowdMinGap,
        public array $rounds,
        public array $profiles,
        public int $flowId,
        public string $flowName,
        public array $steps,
        public array $locales,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        if (($data['schema'] ?? null) !== self::SCHEMA) {
            throw new InvalidArgumentException('Unknown game version snapshot schema.');
        }

        $game = (array) $data['game'];
        $scoring = (array) $data['scoring'];
        $flow = (array) $data['flow'];

        return new self(
            gameId: (int) $game['id'],
            slug: (string) $game['slug'],
            mechanic: GameMechanic::from((string) $game['mechanic']),
            title: LocalizedText::of($game['title'] ?? []),
            description: LocalizedText::of($game['description'] ?? []),
            coverAssetId: isset($game['cover_asset_id']) ? (int) $game['cover_asset_id'] : null,
            pointsPerRound: (int) $scoring['points_per_round'],
            crowdMinVotes: (int) $scoring['crowd_min_votes'],
            crowdMinGap: (int) $scoring['crowd_min_gap'],
            rounds: array_values(array_map(fn (array $round): SnapshotRound => SnapshotRound::fromArray($round), (array) $data['rounds'])),
            profiles: array_values(array_map(fn (array $profile): SnapshotProfile => SnapshotProfile::fromArray($profile), (array) $data['profiles'])),
            flowId: (int) $flow['id'],
            flowName: (string) $flow['name'],
            steps: array_values(array_map(fn (array $step): SnapshotStep => SnapshotStep::fromArray($step), (array) $flow['steps'])),
            locales: array_values(array_map('strval', (array) ($data['locales'] ?? []))),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'game' => [
                'id' => $this->gameId,
                'slug' => $this->slug,
                'mechanic' => $this->mechanic->value,
                'title' => $this->title->toArray(),
                'description' => $this->description->toArray(),
                'cover_asset_id' => $this->coverAssetId,
            ],
            'scoring' => [
                'points_per_round' => $this->pointsPerRound,
                'crowd_min_votes' => $this->crowdMinVotes,
                'crowd_min_gap' => $this->crowdMinGap,
            ],
            'rounds' => array_map(fn (SnapshotRound $round): array => $round->toArray(), $this->rounds),
            'profiles' => array_map(fn (SnapshotProfile $profile): array => $profile->toArray(), $this->profiles),
            'flow' => [
                'id' => $this->flowId,
                'name' => $this->flowName,
                'steps' => array_map(fn (SnapshotStep $step): array => $step->toArray(), $this->steps),
            ],
            'locales' => $this->locales,
        ];
    }

    public function roundCount(): int
    {
        return count($this->rounds);
    }

    public function scoredRoundCount(): int
    {
        return count(array_filter($this->rounds, fn (SnapshotRound $round): bool => $round->scored));
    }

    public function maxScore(): int
    {
        return $this->scoredRoundCount() * $this->pointsPerRound;
    }

    public function round(int $index): ?SnapshotRound
    {
        return $this->rounds[$index] ?? null;
    }

    public function profileFor(int $matches): ?SnapshotProfile
    {
        $index = ResultProfileCoverage::profileFor(
            array_map(fn (SnapshotProfile $profile): ResultProfileRange => new ResultProfileRange($profile->name->english(), $profile->min, $profile->max), $this->profiles),
            $matches,
        );

        return $index === null ? null : $this->profiles[$index];
    }

    public function profile(string $key): ?SnapshotProfile
    {
        foreach ($this->profiles as $profile) {
            if ($profile->key === $key) {
                return $profile;
            }
        }

        return null;
    }

    public function step(string $key): ?SnapshotStep
    {
        foreach ($this->steps as $step) {
            if ($step->key === $key) {
                return $step;
            }
        }

        return null;
    }

    /** @return list<FlowStepDraft> */
    public function flowSteps(): array
    {
        return array_map(fn (SnapshotStep $step): FlowStepDraft => $step->draft(), $this->steps);
    }

    /**
     * Whether every scored round is decided by the crowd, so a result can be
     * told as matching the crowd rather than as being right.
     */
    public function scoresByCrowdOnly(): bool
    {
        foreach ($this->rounds as $round) {
            if ($round->scored && $round->mode !== GameRoundMode::Crowd) {
                return false;
            }
        }

        return true;
    }

    /** @return list<int> the posts the version shows */
    public function postIds(): array
    {
        $ids = [];

        foreach ($this->rounds as $round) {
            foreach ($round->options as $option) {
                if ($option->postId !== null) {
                    $ids[] = $option->postId;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * The uploaded pictures the version shows — cover, uploaded options,
     * profiles' share images — which the version keeps alive. A post's
     * picture is the post's.
     *
     * @return list<int>
     */
    public function uploadedAssetIds(): array
    {
        $ids = $this->coverAssetId === null ? [] : [$this->coverAssetId];

        foreach ($this->rounds as $round) {
            foreach ($round->options as $option) {
                if ($option->postId === null && $option->assetId !== null) {
                    $ids[] = $option->assetId;
                }
            }
        }

        foreach ($this->profiles as $profile) {
            if ($profile->shareAssetId !== null) {
                $ids[] = $profile->shareAssetId;
            }
        }

        return array_values(array_unique($ids));
    }
}
