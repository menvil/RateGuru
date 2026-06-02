<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;

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
            'author' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'username' => $this->user->username,
                'display_name' => $this->user->name,
                'avatar_url' => $this->user->avatar_url,
                'profile_url' => $this->profileUrl(),
            ], null),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    private function profileUrl(): ?string
    {
        if (! $this->user->username || ! Route::has('profile.show')) {
            return null;
        }

        return route('profile.show', ['username' => $this->user->username], absolute: true);
    }
}
