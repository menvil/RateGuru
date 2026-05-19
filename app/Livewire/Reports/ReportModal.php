<?php

namespace App\Livewire\Reports;

use Illuminate\Contracts\View\View;
use Livewire\Component;

final class ReportModal extends Component
{
    public string $reportableType;

    public int $reportableId;

    public string $reason = '';

    public ?string $message = null;

    public bool $submitted = false;

    public function render(): View
    {
        return view('livewire.reports.report-modal');
    }
}
