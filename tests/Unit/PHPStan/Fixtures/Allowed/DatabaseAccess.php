<?php

declare(strict_types=1);

namespace Tests\PHPStan\Fixtures;

use Illuminate\Support\Facades\DB;

final class ApprovedDatabaseAccess
{
    public function read(): void
    {
        DB::table('legacy_records')->count();
    }
}

final class TransactionalAction
{
    public function execute(): void
    {
        DB::transaction(static fn (): null => null);
    }
}
