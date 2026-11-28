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
        // A video or animated GIF instead of a picture: the post waits as a
        // draft until it has been processed.
        public ?UploadedFile $video = null,
        // A video or animated GIF to download from a link — a file's, or the
        // one a post on another site carries — in the background.
        public ?string $videoUrl = null,
        // The link the picture or video was taken from, as pasted — for
        // moderators to open the original. Null for a file uploaded.
        public ?string $takenFrom = null,
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
