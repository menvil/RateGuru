<?php

namespace App\Data\Posts;

use App\Enums\CuisineType;
use App\Enums\OriginType;
use Illuminate\Http\UploadedFile;

final readonly class CreatePostData
{
    public function __construct(
        public string $title,
        public ?string $description = null,
        public ?string $sourceUrl = null,
        public OriginType $originTruth = OriginType::Unknown,
        public CuisineType $cuisineTruth = CuisineType::Unknown,
        /** @var array<int> $tagIds */
        public array $tagIds = [],
        public ?UploadedFile $image = null,
    ) {}
}
