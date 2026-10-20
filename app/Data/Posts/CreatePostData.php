<?php

namespace App\Data\Posts;

use App\Enums\ImageInputSource;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;

final readonly class CreatePostData
{
    public function __construct(
        public string $title,
        public ?string $description = null,
        public ?string $sourceUrl = null,
        /** @var array<int> $tagIds */
        public array $tagIds = [],
        public ?UploadedFile $image = null,
        public ImageInputSource $imageSource = ImageInputSource::Upload,
        // Optional standalone taxonomy selected by the author.
        public ?int $categoryId = null,
        /**
         * Author's claimed correct answers ("I know the correct answer"):
         * active rating option ids, at most one per active rating group.
         *
         * @var array<int> $authorAnswerOptionIds
         */
        public array $authorAnswerOptionIds = [],
        /*
         * Trusted, and set only by Content Studio's executor
         * (ExecuteStudioAction), never from a request: a Ready post image
         * already stored — an approved plan's photo, used as it is — and the
         * post's own dates, a past or a scheduled one. Null, as everywhere
         * else, keeps the post as an upload makes it.
         */
        public ?int $imageAssetId = null,
        public ?CarbonImmutable $createdAt = null,
        public ?CarbonImmutable $updatedAt = null,
    ) {}
}
