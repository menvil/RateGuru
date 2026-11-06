<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Str;
use RuntimeException;

class GenerateUniqueUsernameAction
{
    public const int MAX_ATTEMPTS = 100;

    public function handle(string $name): string
    {
        $base = Str::of($name)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->limit(32, '')
            ->value();

        if ($base === '') {
            $base = 'user';
        }

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $suffix = $attempt === 1 ? '' : '_'.$attempt;
            $candidate = Str::limit($base, 32 - strlen($suffix), '').$suffix;

            if (! User::query()->where('username', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to generate a unique username.');
    }
}
