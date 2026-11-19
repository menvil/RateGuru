<?php

namespace App\Support\Traffic\Attribution;

use App\Enums\AccountSignupPlace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Where on the site an account was signed up, by the page the sign-up came
 * back to: a game (its player or a result), a post, the feed, or anywhere
 * else.
 */
final class SignupPlace
{
    private const array GAME_ROUTES = ['games.play', 'games.result', 'games.player-result'];

    public static function of(?string $path): AccountSignupPlace
    {
        if ($path === null || ! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return AccountSignupPlace::Other;
        }

        try {
            $name = Route::getRoutes()->match(Request::create((string) parse_url($path, PHP_URL_PATH)))->getName();
        } catch (Throwable) {
            return AccountSignupPlace::Other;
        }

        return match (true) {
            in_array($name, self::GAME_ROUTES, true) => AccountSignupPlace::Game,
            $name === 'posts.show' => AccountSignupPlace::Post,
            $name === 'feed' => AccountSignupPlace::Feed,
            default => AccountSignupPlace::Other,
        };
    }
}
