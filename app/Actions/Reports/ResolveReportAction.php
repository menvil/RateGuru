<?php

namespace App\Actions\Reports;

use App\Models\Report;
use App\Models\User;

final class ResolveReportAction
{
    public function handle(User $moderator, Report $report, ?string $note = null): void
    {
        throw new \LogicException('Not implemented yet.');
    }
}
