<?php

namespace App\Support\Observability;

use App\Models\Post;
use App\Models\User;

final class LogContext
{
    public function base(): array
    {
        $context = [
            'request_id' => app()->bound('request_id') ? app('request_id') : (string) \Illuminate\Support\Str::uuid(),
            'app_env' => app()->environment(),
            'locale' => app()->getLocale(),
        ];

        $routeName = optional(request()->route())->getName();
        if ($routeName !== null) {
            $context['route_name'] = $routeName;
        }

        $userId = auth()->id();
        if ($userId !== null) {
            $context['user_id'] = $userId;
        }

        $theme = auth()->user()?->theme_preference;
        if ($theme !== null) {
            $context['theme_preference'] = $theme;
        }

        return $context;
    }

    public function forPost(Post $post): array
    {
        return [
            'post_id' => $post->id,
        ];
    }

    public function forUser(User $user): array
    {
        return [
            'user_id' => $user->id,
            'username' => $user->username,
        ];
    }

    public function forImport(?string $url = null, ?string $provider = null): array
    {
        $context = [];

        if ($url !== null) {
            $parsed = parse_url($url);
            $context['source_host'] = $parsed['host'] ?? 'unknown';
        }

        if ($provider !== null) {
            $context['provider'] = $provider;
        }

        return $context;
    }

    public function merge(array ...$contexts): array
    {
        if (empty($contexts)) {
            return [];
        }

        return array_merge(...$contexts);
    }
}
