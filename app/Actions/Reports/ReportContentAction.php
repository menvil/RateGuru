<?php

namespace App\Actions\Reports;

use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Exceptions\Reports\CannotReportContentException;
use App\Models\Comment;
use App\Models\Post;
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
        if ($user === null) {
            throw CannotReportContentException::becauseGuest();
        }

        if (! $user->canReport()) {
            throw CannotReportContentException::becauseUserIsNotAllowed();
        }

        if (! $content instanceof Post && ! $content instanceof Comment) {
            throw CannotReportContentException::becauseUnsupportedContent();
        }

        $message = trim((string) $message);
        $message = $message === '' ? null : $message;

        return Report::create([
            'reporter_id' => $user->id,
            'target_type' => $content::class,
            'target_id' => $content->id,
            'reason' => $reason,
            'message' => $message,
            'status' => ReportStatus::Open,
        ]);
    }
}
