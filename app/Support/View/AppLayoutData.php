<?php

namespace App\Support\View;

final class AppLayoutData
{
    /**
     * @return array{isFeedRoute: bool, profileHref: string|null}
     */
    public function toArray(): array
    {
        $user = auth()->user();

        return [
            'isFeedRoute' => request()->routeIs('feed'),
            'profileHref' => $user
                ? (filled($user->username) ? route('profile.show', ['username' => $user->username]) : route('profile.edit'))
                : null,
        ];
    }
}
