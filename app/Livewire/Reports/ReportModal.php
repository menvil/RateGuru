<?php

namespace App\Livewire\Reports;

use App\Actions\Reports\ReportContentAction;
use App\Enums\CommentStatus;
use App\Enums\ReportReason;
use App\Exceptions\Reports\CannotReportContentException;
use App\Models\Comment;
use App\Models\Post;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Livewire\Component;

final class ReportModal extends Component
{
    public string $reportableType;

    public int $reportableId;

    public string $variant = 'link';

    public string $reason = '';

    public ?string $message = null;

    public bool $submitted = false;

    public function submit(ReportContentAction $reportContentAction): void
    {
        $this->resetErrorBag();

        $this->validate([
            'reason' => ['required', Rule::enum(ReportReason::class)],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        $content = $this->resolveReportable();

        try {
            $reportContentAction->handle(
                user: auth()->user(),
                content: $content,
                reason: ReportReason::from($this->reason),
                message: $this->message,
            );
        } catch (CannotReportContentException $e) {
            $this->addError('report', $e->getMessage());

            return;
        }

        $this->submitted = true;

        $this->dispatch(
            'content-reported',
            type: $this->reportableType,
            id: $this->reportableId,
        );
    }

    private function resolveReportable(): Model
    {
        $content = match ($this->reportableType) {
            'post' => Post::query()
                ->published()
                ->find($this->reportableId),

            'comment' => Comment::query()
                ->where('status', CommentStatus::Visible)
                ->find($this->reportableId),

            default => abort(404),
        };

        abort_if($content === null, 404);

        return $content;
    }

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
