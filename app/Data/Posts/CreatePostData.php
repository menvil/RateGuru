<?php

namespace App\Data\Posts;

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
        // Author-chosen feed category: an active option of the first active
        // rating group (the one the sidebar "Categories" block is built from).
        public ?int $categoryOptionId = null,
        /**
         * Author's claimed correct answers ("I know the correct answer"):
         * active rating option ids, at most one per active rating group.
         *
         * @var array<int> $authorAnswerOptionIds
         */
        public array $authorAnswerOptionIds = [],
    ) {}
}
