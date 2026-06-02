<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CommentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'post_id' => $this->post_id,
            'body' => $this->body,
            'author' => $this->whenLoaded(
                'user',
                fn () => UserResource::make($this->user)->resolve($request),
                null,
            ),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
