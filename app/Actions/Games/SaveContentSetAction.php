<?php

namespace App\Actions\Games;

use App\Actions\Games\Concerns\LocksGameDraft;
use App\Data\Games\ContentSetDraft;
use App\Enums\GameOptionSource;
use App\Enums\GameRoundMode;
use App\Enums\ImageInputSource;
use App\Enums\MediaKind;
use App\Exceptions\Games\CannotManageGameException;
use App\Jobs\GenerateMediaVariantsJob;
use App\Models\GameContentSet;
use App\Models\GameResultProfile;
use App\Models\GameRound;
use App\Models\GameRoundOption;
use App\Models\User;
use App\Services\Media\Exceptions\ImageIngestException;
use App\Services\Media\MediaLifecycleService;
use App\Services\Media\MediaStoreRequest;
use App\Services\Media\StoredMedia;
use App\Services\Media\StoredMediaCleaner;
use App\Support\Games\ContentSetDraftRules;
use App\Support\Media\ImageUploadStorer;
use App\Support\Media\MediaAssetCreator;
use App\Support\Observability\DomainLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Saves a content set as the Content tab holds it: scoring, rounds in order
 * with their options, result profiles. Rows the draft no longer has are
 * removed; new ones are made; the rest are updated. Translations stay on
 * their rows.
 *
 * Pictures chosen on the tab arrive as uploads, keyed by where they go
 * (`round:{key}:{position}`, `profile:{key}`), and go through the media path
 * a post's own image takes: checked and stored before the transaction, made
 * into assets inside it, their web sizes made after the commit. An option or
 * a profile may keep only a picture this set already shows; a picture the set
 * no longer shows is released through the media lifecycle.
 *
 * A published version never changes: it keeps its own snapshot.
 */
final class SaveContentSetAction
{
    use LocksGameDraft;

    public function __construct(
        private readonly ContentSetDraftRules $rules,
        private readonly ImageUploadStorer $imageUploadStorer,
        private readonly MediaAssetCreator $mediaAssetCreator,
        private readonly StoredMediaCleaner $storedMediaCleaner,
        private readonly MediaLifecycleService $lifecycleService,
        private readonly DomainLogger $logger,
    ) {}

    /**
     * @param  array<string, UploadedFile>  $uploads  by where each goes
     *
     * @throws CannotManageGameException
     * @throws ValidationException keyed as the draft's paths
     * @throws ImageIngestException when an upload is not an image the site takes
     */
    public function handle(User $admin, GameContentSet $set, ContentSetDraft $draft, int $expectedLockVersion, array $uploads = []): void
    {
        if (! $admin->can('manage-games')) {
            throw CannotManageGameException::becauseUserIsNotAllowed();
        }

        $draft = $this->rules->validated($draft, $set);

        /** @var array<string, StoredMedia> $stored */
        $stored = [];

        try {
            foreach ($uploads as $place => $file) {
                $stored[$place] = $this->imageUploadStorer->store($file, MediaStoreRequest::forPostImage((int) $admin->getKey()), ImageInputSource::Upload);
            }

            $newAssetIds = DB::transaction(fn (): array => $this->write($admin, $set, $draft, $expectedLockVersion, $stored));
        } catch (Throwable $exception) {
            foreach ($stored as $media) {
                $this->storedMediaCleaner->deleteIfUnclaimed($media);
            }

            throw $exception;
        }

        $this->logger->info('game.content_set.saved', ['game_id' => $set->game_id, 'content_set_id' => $set->id, 'admin_id' => $admin->id]);

        foreach ($newAssetIds as $assetId) {
            try {
                GenerateMediaVariantsJob::dispatch($assetId)->afterCommit();
            } catch (Throwable $exception) {
                report($exception);
                Log::error('Failed to dispatch or run media variant generation.', ['content_set_id' => $set->id, 'media_asset_id' => $assetId, 'exception' => $exception->getMessage()]);
            }
        }
    }

    /**
     * @param  array<string, StoredMedia>  $stored
     * @return list<int> the assets made for the uploads
     */
    private function write(User $admin, GameContentSet $set, ContentSetDraft $draft, int $expectedLockVersion, array $stored): array
    {
        $lockedAdmin = $this->lockAdministrator($admin);
        $game = $this->lockGame((int) $set->game_id);
        $locked = $this->lockContentSet($game, (int) $set->id, $expectedLockVersion);
        $locked->load(['rounds.options', 'profiles']);

        $before = $this->assetIdsOf($locked);
        $assets = [];

        foreach ($stored as $place => $media) {
            $assets[$place] = (int) $this->mediaAssetCreator->create($media, MediaKind::PostImage, $lockedAdmin)->id;
        }

        $allowed = [...$before, ...array_values($assets)];
        $keptRounds = [];

        foreach ($draft->rounds as $position => $data) {
            $round = $data['id'] !== null ? $locked->rounds->firstWhere('id', $data['id']) : null;
            $round ??= new GameRound;
            $round->forceFill([
                'content_set_id' => $locked->id,
                'position' => $position,
                'internal_name' => $data['internal_name'],
                'question' => $data['question'] !== '' ? $data['question'] : null,
                'description' => $data['description'] !== '' ? $data['description'] : null,
                'reveal_message' => $data['reveal_message'] !== '' ? $data['reveal_message'] : null,
                'mode' => GameRoundMode::from($data['mode']),
                'unscored' => $data['unscored'],
                'show_crowd' => $data['show_crowd'],
            ])->save();
            $keptRounds[] = (int) $round->id;

            foreach ($data['options'] as $slot => $option) {
                $place = "round:{$data['key']}:{$slot}";
                $assetId = $assets[$place] ?? $option['asset_id'];

                if ($assetId !== null && ! in_array($assetId, $allowed, true)) {
                    throw CannotManageGameException::because('A picture can only be uploaded here, not taken from elsewhere.');
                }

                $row = $round->exists && $round->relationLoaded('options')
                    ? $round->options->firstWhere('position', $slot)
                    : GameRoundOption::query()->where('round_id', $round->id)->where('position', $slot)->first();
                $row ??= new GameRoundOption;
                $source = isset($assets[$place]) ? GameOptionSource::Upload : GameOptionSource::from($option['source']);
                $row->forceFill([
                    'round_id' => $round->id,
                    'position' => $slot,
                    'source' => $source,
                    'post_id' => $source === GameOptionSource::Post ? $option['post_id'] : null,
                    'asset_id' => $source === GameOptionSource::Upload ? $assetId : null,
                    'label' => $option['label'] !== '' ? $option['label'] : null,
                    'is_correct' => $option['is_correct'],
                ])->save();
            }
        }

        $locked->rounds->reject(fn (GameRound $round): bool => in_array((int) $round->id, $keptRounds, true))
            ->each(fn (GameRound $round) => $round->delete());

        $keptProfiles = [];

        foreach ($draft->profiles as $position => $data) {
            $place = 'profile:'.($data['client_key'] ?? '');
            $assetId = $assets[$place] ?? ($data['share_asset_id'] ?? null);

            if ($assetId !== null && ! in_array((int) $assetId, $allowed, true)) {
                throw CannotManageGameException::because('A picture can only be uploaded here, not taken from elsewhere.');
            }

            $profile = isset($data['id']) ? $locked->profiles->firstWhere('id', $data['id']) : null;
            $profile ??= new GameResultProfile;
            $profile->forceFill([
                'content_set_id' => $locked->id,
                'position' => $position,
                'key' => $data['key'],
                'name' => $data['name'],
                'description' => $data['description'] !== '' ? $data['description'] : null,
                'min_matches' => $data['min'],
                'max_matches' => $data['max'],
                'icon' => $data['icon'],
                'share_text' => $data['share_text'] !== '' ? $data['share_text'] : null,
                'share_asset_id' => $assetId !== null ? (int) $assetId : null,
            ]);
            $keptProfiles[] = $profile;
        }

        // Keys are unique within a set: profiles that go are deleted first, and
        // the rest move through a temporary key, so two profiles can swap keys.
        // An underscore keeps it from ever being a profile's own key: those are
        // slugs, which never hold one.
        $keptIds = array_filter(array_map(fn (GameResultProfile $profile): ?int => $profile->exists ? (int) $profile->id : null, $keptProfiles));
        $locked->profiles->reject(fn (GameResultProfile $profile): bool => in_array((int) $profile->id, $keptIds, true))
            ->each(fn (GameResultProfile $profile) => $profile->delete());

        foreach ($keptProfiles as $profile) {
            if ($profile->exists && $profile->isDirty('key')) {
                GameResultProfile::query()->whereKey($profile->id)->update(['key' => '_moving_'.$profile->id]);
            }
        }

        foreach ($keptProfiles as $profile) {
            $profile->save();
        }

        $locked->forceFill([
            'points_per_round' => $draft->scoring['points_per_round'],
            'crowd_min_votes' => $draft->scoring['crowd_min_votes'],
            'crowd_min_gap' => $draft->scoring['crowd_min_gap'],
            'lock_version' => $locked->lock_version + 1,
        ])->save();
        $this->touchGame($game, $lockedAdmin);

        $after = $this->assetIdsOf($locked->fresh(['rounds.options', 'profiles']));
        $released = array_values(array_diff($before, $after));

        if ($released !== []) {
            $this->lifecycleService->releaseUnreferenced(collect($released));
        }

        $set->setRawAttributes($locked->getAttributes(), true);

        return array_values($assets);
    }

    /** @return list<int> the uploaded pictures the set shows now */
    private function assetIdsOf(GameContentSet $set): array
    {
        $ids = [];

        foreach ($set->rounds as $round) {
            foreach ($round->options as $option) {
                if ($option->asset_id !== null) {
                    $ids[] = (int) $option->asset_id;
                }
            }
        }

        foreach ($set->profiles as $profile) {
            if ($profile->share_asset_id !== null) {
                $ids[] = (int) $profile->share_asset_id;
            }
        }

        return array_values(array_unique($ids));
    }
}
