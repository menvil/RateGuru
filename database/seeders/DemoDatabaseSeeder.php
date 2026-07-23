<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DemoDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $this->call([
            DefaultCategorySeeder::class,
            DefaultRatingConfigurationSeeder::class,
            DemoUsersSeeder::class,
            DemoAdminSeeder::class,
            DemoModeratorSeeder::class,
            DemoTagsSeeder::class,
            DemoPublishedPostsSeeder::class,
            DemoPendingPostsSeeder::class,
            DemoHiddenPostsSeeder::class,
            DemoCommentsSeeder::class,
            DemoVotesSeeder::class,
            DemoReportsSeeder::class,
        ]);
    }
}
