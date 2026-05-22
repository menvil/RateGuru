<?php

namespace App\Filament\Widgets;

use App\Actions\Comments\HideCommentAction;
use App\Actions\Moderation\ApprovePostAction;
use App\Actions\Moderation\HidePostAction;
use App\Actions\Reports\ResolveReportAction;
use App\Enums\CommentStatus;
use App\Enums\PostStatus;
use App\Enums\ReportStatus;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Report;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class LatestReportsTable extends TableWidget
{
    protected static bool $isLazy = false;

    public function table(Table $table): Table
    {
        return $table
            ->heading('Latest reports')
            ->query(
                Report::query()
                    ->with(['reporter', 'target'])
                    ->latest()
                    ->limit(10),
            )
            ->paginated(false)
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('target_type')
                    ->label('Target')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        Post::class => 'Post',
                        Comment::class => 'Comment',
                        default => 'Unknown',
                    }),
                TextColumn::make('reason')
                    ->label('Reason')
                    ->badge(),
                TextColumn::make('reporter.username')
                    ->label('Reporter')
                    ->placeholder('-'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (ReportStatus|string|null $state): string => match ($state) {
                        ReportStatus::Open, 'open' => 'warning',
                        ReportStatus::Resolved, 'resolved' => 'success',
                        ReportStatus::Ignored, 'ignored' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime(),
            ])
            ->recordActions([
                Action::make('approvePost')
                    ->label('Approve post')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Report $record): bool => $record->target instanceof Post
                        && $record->target->status === PostStatus::Pending)
                    ->schema([
                        Textarea::make('reason')
                            ->label('Reason')
                            ->maxLength(1000),
                    ])
                    ->requiresConfirmation()
                    ->action(function (Report $record, array $data): void {
                        $target = $record->target;

                        if (! $target instanceof Post) {
                            return;
                        }

                        app(ApprovePostAction::class)->handle(
                            auth()->user(),
                            $target,
                            $data['reason'] ?? null,
                        );
                    }),
                Action::make('hideTarget')
                    ->label('Hide target')
                    ->icon('heroicon-o-eye-slash')
                    ->color('danger')
                    ->visible(function (Report $record): bool {
                        $target = $record->target;

                        return ($target instanceof Post && $target->status === PostStatus::Published)
                            || ($target instanceof Comment && $target->status === CommentStatus::Visible);
                    })
                    ->schema([
                        Textarea::make('reason')
                            ->label('Reason')
                            ->maxLength(1000),
                    ])
                    ->requiresConfirmation()
                    ->action(function (Report $record, array $data): void {
                        $target = $record->target;
                        $reason = $data['reason'] ?? null;

                        if ($target instanceof Post) {
                            app(HidePostAction::class)->handle(auth()->user(), $target, $reason);

                            return;
                        }

                        if ($target instanceof Comment) {
                            app(HideCommentAction::class)->handle(auth()->user(), $target, $reason);
                        }
                    }),
                Action::make('resolveReport')
                    ->label('Resolve')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (Report $record): bool => $record->status === ReportStatus::Open)
                    ->schema([
                        Textarea::make('note')
                            ->label('Resolution note')
                            ->maxLength(1000),
                    ])
                    ->requiresConfirmation()
                    ->action(function (Report $record, array $data): void {
                        app(ResolveReportAction::class)->handle(
                            auth()->user(),
                            $record,
                            $data['note'] ?? null,
                        );
                    }),
            ]);
    }
}
