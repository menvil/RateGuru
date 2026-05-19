<?php

namespace App\Livewire\Reports;

use App\Enums\ReportReason;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class ReportModal extends Component
{
    public string $reportableType;

    public int $reportableId;

    public string $reason = '';

    public ?string $message = null;

    public bool $submitted = false;

    /**
     * @return list<array{value: string, label: string}>
     */
    public function getReasonsProperty(): array
    {
        return collect(ReportReason::cases())
            ->map(fn (ReportReason $reason) => [
                'value' => $reason->value,
                'label' => $this->labelForReason($reason),
            ])
            ->all();
    }

    private function labelForReason(ReportReason $reason): string
    {
        return match ($reason) {
            ReportReason::Spam => 'Spam',
            ReportReason::Offensive => 'Offensive',
            ReportReason::Fake => 'Fake',
            ReportReason::Copyright => 'Copyright',
            ReportReason::NotFood => 'Not food',
            ReportReason::Other => 'Other',
        };
    }

    public function render(): View
    {
        return view('livewire.reports.report-modal');
    }
}
