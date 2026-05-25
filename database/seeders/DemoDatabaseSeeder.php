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
            DemoUsersSeeder::class,
        ]);
    }
}
