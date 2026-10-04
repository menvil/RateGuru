<?php

namespace App\Livewire\Reports;

use App\Actions\Reports\ReportContentAction;
use App\Enums\CommentStatus;
use App\Enums\ReportReason;
use App\Exceptions\Reports\CannotReportContentException;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
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

            'user' => User::query()
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
            ReportReason::Spam => __('ui.report.reasons.spam'),
            ReportReason::Offensive => __('ui.report.reasons.offensive'),
            ReportReason::Fake => __('ui.report.reasons.fake'),
            ReportReason::Copyright => __('ui.report.reasons.copyright'),
            ReportReason::WrongCategory => __('ui.report.reasons.wrong_category'),
            ReportReason::Other => __('ui.report.reasons.other'),
        };
    }

    public function render(): View
    {
        return view('livewire.reports.report-modal');
    }
}
