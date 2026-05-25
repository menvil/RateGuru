<?php

namespace Database\Seeders;

use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

class DemoReportsSeeder extends Seeder
{
    private const SEED_TIME = '2026-05-20 12:00:00';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $reporter = User::query()->where('email', 'alice@rateguru.test')->firstOrFail();
        $resolver = User::query()->where('email', 'trusted@rateguru.test')->firstOrFail();
        $seedTime = CarbonImmutable::parse(self::SEED_TIME);

        $targets = [
            [
                'target' => Post::query()->where('title', 'Demo: Restaurant Sushi Plate')->firstOrFail(),
                'reporter' => $reporter,
                'reason' => ReportReason::Spam,
                'message' => 'Demo open report for post moderation testing.',
                'status' => ReportStatus::Open,
            ],
            [
                'target' => Comment::query()->orderBy('id')->firstOrFail(),
                'reporter' => User::query()->where('email', 'bob@rateguru.test')->firstOrFail(),
                'reason' => ReportReason::Offensive,
                'message' => 'Demo open report for comment moderation testing.',
                'status' => ReportStatus::Open,
            ],
            [
                'target' => Post::query()->where('title', 'Demo: Mexican Street Tacos')->firstOrFail(),
                'reporter' => User::query()->where('email', 'carla@rateguru.test')->firstOrFail(),
                'reason' => ReportReason::Fake,
                'message' => 'Demo resolved report for report resource checks.',
                'status' => ReportStatus::Resolved,
                'resolved_by' => $resolver->id,
                'resolved_at' => $seedTime->subHour(),
                'resolution_note' => 'Demo resolution note.',
            ],
            [
                'target' => Comment::query()->orderByDesc('id')->firstOrFail(),
                'reporter' => $reporter,
                'reason' => ReportReason::Other,
                'message' => 'Demo ignored report for filter checks.',
                'status' => ReportStatus::Ignored,
                'resolved_by' => $resolver->id,
                'resolved_at' => $seedTime->subMinutes(30),
                'resolution_note' => 'Demo ignored report note.',
            ],
        ];

        foreach ($targets as $report) {
            /** @var Model $target */
            $target = $report['target'];

            Report::query()->updateOrCreate(
                [
                    'reporter_id' => $report['reporter']->id,
                    'target_type' => $target::class,
                    'target_id' => $target->getKey(),
                ],
                [
                    'reason' => $report['reason'],
                    'message' => $report['message'],
                    'status' => $report['status'],
                    'resolved_by' => $report['resolved_by'] ?? null,
                    'resolved_at' => $report['resolved_at'] ?? null,
                    'resolution_note' => $report['resolution_note'] ?? null,
                ],
            );

            $this->refreshReportsCount($target);
        }
    }

    private function refreshReportsCount(Model $target): void
    {
        $target->forceFill([
            'reports_count' => Report::query()
                ->where('target_type', $target::class)
                ->where('target_id', $target->getKey())
                ->count(),
        ])->save();
    }
}
