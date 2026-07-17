<?php

namespace App\Http\Resources\Api;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;

/** @mixin User */
final class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'display_name' => $this->name,
            'avatar_url' => $this->avatar_url,
            'profile_url' => $this->profileUrl(),
        ];
    }

    private function profileUrl(): ?string
    {
        if (! $this->username || ! Route::has('profile.show')) {
            return null;
        }

        return rtrim((string) config('app.url'), '/').route('profile.show', ['username' => $this->username], absolute: false);
    }
}
