<?php

namespace App\Http\Resources\Api;

use App\Support\Urls\PostUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;

final class PostResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'image_url' => $this->public_image_url,
            'thumbnail_url' => $this->thumbnail_url,
            'canonical_url' => $this->canonicalUrl(),
            'author' => $this->whenLoaded(
                'user',
                fn () => UserResource::make($this->user)->resolve($request),
                null,
            ),
            'tags' => $this->whenLoaded('tags', fn () => $this->tags
                ->map(fn ($tag) => [
                    'name' => $tag->name,
                    'slug' => $tag->slug,
                ])
                ->values()
                ->all(), []),
            'stats' => [
                'upvotes_count' => (int) $this->upvotes_count,
                'downvotes_count' => (int) $this->downvotes_count,
                'comments_count' => (int) $this->comments_count,
            ],
            'scores' => [
                'hot_score' => (float) $this->hot_score,
            ],
            'created_at' => $this->created_at?->toISOString(),
            'published_at' => $this->published_at?->toISOString(),
        ];
    }

    private function canonicalUrl(): ?string
    {
        if (! Route::has('posts.show')) {
            return null;
        }

        return app(PostUrl::class)->canonical($this->resource);
    }
}
