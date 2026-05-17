<?php

namespace App\Actions\Posts;

use App\Data\Posts\CreatePostData;
use App\Enums\PostStatus;
use App\Enums\UserStatus;
use App\Exceptions\Posts\CannotCreatePostException;
use App\Jobs\ProcessUploadedImageJob;
use App\Models\Post;
use App\Models\User;
use App\Services\Images\ImageStorage;
use Illuminate\Support\Facades\DB;

final class CreatePostAction
{
    public function __construct(
        private readonly ImageStorage $imageStorage,
    ) {}

    public function handle(User $user, CreatePostData $data): Post
    {
        if (! $user->canCreateContent()) {
            throw CannotCreatePostException::becauseUserIsNotAllowed();
        }

        $isTrusted = $user->trust_level >= 10 && $user->status === UserStatus::Active;

        $status      = $isTrusted ? PostStatus::Published : PostStatus::Pending;
        $publishedAt = $isTrusted ? now() : null;

        $post = DB::transaction(function () use ($user, $data, $status, $publishedAt) {
            $storedImage = $data->image !== null
                ? $this->imageStorage->storePostImage($data->image, $user)
                : null;

            $post = Post::create([
                'user_id'       => $user->id,
                'title'         => $data->title,
                'description'   => $data->description,
                'source_url'    => $data->sourceUrl,
                'origin_truth'  => $data->originTruth,
                'cuisine_truth' => $data->cuisineTruth,
                'status'        => $status,
                'published_at'  => $publishedAt,
                'image_path'    => $storedImage?->path,
                'image_url'     => $storedImage?->url,
                'thumbnail_url' => $storedImage?->thumbnailUrl,
            ]);

            if ($data->tagIds !== []) {
                $post->tags()->sync($data->tagIds);
            }

            return $post;
        });

        if ($post->image_path !== null) {
            ProcessUploadedImageJob::dispatch($post->id);
        }

        return $post;
    }
}
