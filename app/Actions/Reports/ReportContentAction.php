<?php

namespace App\Actions\Reports;

use App\Enums\ReportReason;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final class ReportContentAction
{
    public function handle(
        ?User $user,
        Model $content,
        ReportReason $reason,
        ?string $message = null,
    ): Report {
        throw new \LogicException('Not implemented yet.');
    }
}
