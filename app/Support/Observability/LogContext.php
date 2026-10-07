<?php

namespace App\Support\Observability;

use Illuminate\Support\Str;

final class LogContext
{
    public function base(): array
    {
        $context = [
            'request_id' => app()->bound('request_id') ? app('request_id') : (string) Str::uuid(),
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
}
