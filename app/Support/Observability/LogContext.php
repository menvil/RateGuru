<?php

namespace App\Support\Observability;

use Illuminate\Support\Str;
use Throwable;

final class LogContext
{
    public function base(): array
    {
        return [...$this->request(), ...$this->visitor()];
    }

    /**
     * What is known about the request itself: available from the first
     * middleware on, before any session is read.
     */
    public function request(): array
    {
        $context = [
            'request_id' => app()->bound('request_id') ? app('request_id') : (string) Str::uuid(),
            'app_env' => app()->environment(),
        ];

        $routeName = optional(request()->route())->getName();
        if ($routeName !== null) {
            $context['route_name'] = $routeName;
        }

        return $context;
    }

    /**
     * Who is asking, and in which language: only true once the session has
     * started and the locale has been resolved.
     */
    public function visitor(): array
    {
        $context = ['locale' => app()->getLocale()];

        // Resolving the user can query the database, and this also runs while
        // an exception is being reported — often because the database just
        // failed. A context that throws there replaces the failure being
        // reported with its own, so the visitor is left out instead, the way
        // the framework's own exception context does it.
        try {
            $user = auth()->user();
        } catch (Throwable) {
            return $context;
        }

        if ($user !== null) {
            $context['user_id'] = $user->getAuthIdentifier();

            if ($user->theme_preference !== null) {
                $context['theme_preference'] = $user->theme_preference;
            }
        }

        return $context;
    }
}
