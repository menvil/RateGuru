<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fixtures;

use Illuminate\Support\Facades\DB;

final class DatabaseAccessController
{
    public function table(): void
    {
        DB::table('users')->count();
    }

    public function select(): void
    {
        DB::select('select 1');
    }

    public function raw(): void
    {
        DB::raw('1');
    }

    public function transaction(): void
    {
        DB::transaction(static fn (): null => null);
    }
}
