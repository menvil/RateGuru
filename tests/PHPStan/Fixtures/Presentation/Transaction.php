<?php

declare(strict_types=1);

namespace App\Filament\Pages\ArchitectureFixtures;

use Illuminate\Support\Facades\DB;

final class Transaction
{
    public function save(): void
    {
        DB::transaction(static fn (): null => null);
    }
}
