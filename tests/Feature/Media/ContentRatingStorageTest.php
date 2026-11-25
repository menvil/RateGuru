<?php

use App\Enums\ClassificationMode;
use App\Enums\ClassificationStatus;
use App\Enums\ContentCategory;
use App\Enums\ContentRating;
use App\Models\MediaAsset;
use App\Models\MediaClassification;
use App\Models\Post;
use App\Services\Media\Classification\ClassificationFrame;
use App\Services\Media\Classification\ClassificationInput;
use App\Services\Media\Classification\ClassificationResult;
use App\Services\Media\Classification\MediaClassificationFailedException;
use App\Services\Media\Classification\MediaClassificationRecorder;
use App\Services\Media\Classification\MediaContentClassifier;
use Illuminate\Support\Facades\Schema;

/*
 * Where a picture's or a video's SFW/NSFW rating is kept, and every
 * classifier's answer about it.
 */

/** A classifier answering $scores, or failing with $failure. */
function standInClassifier(array $scores = [], ?Throwable $failure = null): MediaContentClassifier
{
    return new class($scores, $failure) implements MediaContentClassifier
    {
        public function __construct(private array $scores, private ?Throwable $failure) {}

        public function name(): string
        {
            return 'stand-in';
        }

        public function modelVersion(): ?string
        {
            return 'v1';
        }

        public function classify(ClassificationInput $input): ClassificationResult
        {
            if ($this->failure !== null) {
                throw $this->failure;
            }

            return new ClassificationResult($this->scores, [['at_ms' => 2000, 'scores' => $this->scores]], ['label' => 'Explicit'], 85, 1000);
        }
    };
}

it('starts every asset and post unrated, the post\'s rating indexed for the SFW mode to filter on', function () {
    $asset = MediaAsset::factory()->create()->refresh();
    $post = Post::factory()->create(['image_asset_id' => $asset->id])->refresh();

    expect($asset->content_rating)->toBe(ContentRating::Unknown)
        ->and($asset->content_scores)->toBeNull()
        ->and($asset->content_rating_source)->toBeNull()
        ->and($post->content_rating)->toBe(ContentRating::Unknown)
        ->and(collect(Schema::getIndexes('posts'))->pluck('columns')->contains(['content_rating']))->toBeTrue();
});

it('records a classifier\'s answer — scores per category and per frame, its own answer, time and cost — deciding nothing', function () {
    $asset = MediaAsset::factory()->create();
    $input = new ClassificationInput($asset->id, [new ClassificationFrame('/tmp/frame-2000.png', 2000, 'frame')]);

    $record = app(MediaClassificationRecorder::class)->record($asset, standInClassifier([ContentCategory::BreastExposed->value => 0.91]), $input, ClassificationMode::Shadow);

    expect($record->status)->toBe(ClassificationStatus::Succeeded)
        ->and($record->mode)->toBe(ClassificationMode::Shadow)
        ->and([$record->classifier, $record->model_version])->toBe(['stand-in', 'v1'])
        ->and($record->scores)->toBe(['breast_exposed' => 0.91])
        ->and($record->frame_scores[0]['at_ms'])->toBe(2000)
        ->and($record->inputs['frames'][0])->toBe(['label' => 'frame', 'at_ms' => 2000])
        ->and([$record->latency_ms, $record->cost_microusd])->toBe([85, 1000])
        ->and($asset->refresh()->content_rating)->toBe(ContentRating::Unknown)
        ->and($asset->classifications)->toHaveCount(1);
});

it('records a classifier that failed, with a reason that names no file and holds no key', function () {
    $asset = MediaAsset::factory()->create();
    $input = new ClassificationInput($asset->id, [new ClassificationFrame('/tmp/x.png')]);

    $refused = app(MediaClassificationRecorder::class)->record($asset, standInClassifier(failure: new MediaClassificationFailedException('The provider refused the image.')), $input, ClassificationMode::Active);
    $crashed = app(MediaClassificationRecorder::class)->record($asset, standInClassifier(failure: new RuntimeException('secret key abc in /var/x')), $input, ClassificationMode::Active);

    expect($refused->status)->toBe(ClassificationStatus::Failed)
        ->and($refused->error)->toBe('The provider refused the image.')
        ->and($crashed->error)->toBe(RuntimeException::class)
        ->and($refused->scores)->toBeNull();
});

it('keeps the provider\'s own answer only while it is small', function () {
    $asset = MediaAsset::factory()->create();
    $big = new class implements MediaContentClassifier
    {
        public function name(): string
        {
            return 'chatty';
        }

        public function modelVersion(): ?string
        {
            return null;
        }

        public function classify(ClassificationInput $input): ClassificationResult
        {
            return new ClassificationResult(['sexual_activity' => 0.1], raw: ['blob' => str_repeat('x', 20_000)]);
        }
    };

    $record = app(MediaClassificationRecorder::class)->record($asset, $big, new ClassificationInput($asset->id, []), ClassificationMode::Benchmark);

    expect($record->raw['truncated'])->toBeTrue();
});

it('names the four categories that make a picture NSFW, and only those', function () {
    expect(collect(ContentCategory::cases())->filter->isNsfw()->map->value->values()->all())
        ->toBe(['breast_exposed', 'genitals_exposed', 'sexual_activity', 'sex_toy']);
});

it('drops a classification with its asset', function () {
    $classification = MediaClassification::factory()->create();

    $classification->mediaAsset->forceDelete();

    expect(MediaClassification::query()->count())->toBe(0);
});
