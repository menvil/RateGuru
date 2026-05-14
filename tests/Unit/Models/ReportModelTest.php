<?php

use App\Enums\ReportReason;
use App\Models\Report;

it('casts report reason to ReportReason enum', function () {
    $report = new Report(['reason' => ReportReason::Spam]);

    expect($report->reason)->toBe(ReportReason::Spam);
});
