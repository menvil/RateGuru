<?php

namespace Database\Seeders;

/**
 * LanguageDemoSeeder at a hundred posts a day — the busiest the site's top languages may get — among a thousand
 * members. Takes several minutes.
 *
 *     php artisan db:seed --class=BusyLanguageDemoSeeder
 */
class BusyLanguageDemoSeeder extends LanguageDemoSeeder
{
    protected function memberCount(): int
    {
        return 1000;
    }

    protected function postsPerDay(): int
    {
        return 100;
    }
}
