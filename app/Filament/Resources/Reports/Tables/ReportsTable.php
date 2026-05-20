<?php

namespace App\Filament\Resources\Reports\Tables;

use App\Actions\Comments\HideCommentAction;
use App\Actions\Moderation\HidePostAction;
use App\Actions\Reports\IgnoreReportAction;
use App\Actions\Reports\ResolveReportAction;
use App\Enums\ReportStatus;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Report;
use RuntimeException;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ReportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('target_type')
                    ->label('Target')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        Post::class => 'Post',
                        Comment::class => 'Comment',
                        default => 'Unknown',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        Post::class => 'info',
                        Comment::class => 'gray',
                        default => 'danger',
                    }),
                TextColumn::make('reason')
                    ->label('Reason')
                    ->badge()
                    ->sortable(),
                TextColumn::make('reporter.username')
                    ->label('Reporter')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable()
                    ->color(fn (ReportStatus|string|null $state): string => match ($state) {
                        ReportStatus::Open, 'open' => 'warning',
                        ReportStatus::Resolved, 'resolved' => 'success',
                        ReportStatus::Ignored, 'ignored' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Filter::make('open')
                    ->label('Open')
                    ->query(fn (Builder $query) => $query->where('status', ReportStatus::Open)),
                Filter::make('resolved')
                    ->label('Resolved')
                    ->query(fn (Builder $query) => $query->where('status', ReportStatus::Resolved)),
                Filter::make('ignored')
                    ->label('Ignored')
                    ->query(fn (Builder $query) => $query->where('status', ReportStatus::Ignored)),
            ])
            ->recordActions([
                Action::make('resolve')
                    ->label('Resolve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Report $record): bool =>
                        $record->status === ReportStatus::Open
                        && (auth()->user()?->isModerator() === true
                            || auth()->user()?->isAdmin() === true)
                    )
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
                Action::make('ignore')
                    ->label('Ignore')
                    ->icon('heroicon-o-no-symbol')
                    ->color('gray')
                    ->visible(fn (Report $record): bool =>
                        $record->status === ReportStatus::Open
                        && (auth()->user()?->isModerator() === true
                            || auth()->user()?->isAdmin() === true)
                    )
                    ->schema([
                        Textarea::make('note')
                            ->label('Ignore note')
                            ->maxLength(1000),
                    ])
                    ->requiresConfirmation()
                    ->action(function (Report $record, array $data): void {
                        app(IgnoreReportAction::class)->handle(
                            auth()->user(),
                            $record,
                            $data['note'] ?? null,
                        );
                    }),
                Action::make('hideTarget')
                    ->label('Hide target')
                    ->icon('heroicon-o-eye-slash')
                    ->color('danger')
                    ->visible(fn (Report $record): bool =>
                        $record->target !== null
                        && (auth()->user()?->isModerator() === true
                            || auth()->user()?->isAdmin() === true)
                    )
                    ->schema([
                        Textarea::make('reason')
                            ->label('Reason')
                            ->maxLength(1000),
                    ])
                    ->requiresConfirmation()
                    ->action(function (Report $record, array $data): void {
                        $target = $record->target;
                        $reason = $data['reason'] ?? null;

                        // Dispatch to the target-specific moderation action so
                        // each content type keeps its own audit log, status
                        // guard, and counter refresh. Hiding does NOT change
                        // the report status — that is a separate audit event,
                        // resolved/ignored by the moderator afterwards.
                        match (true) {
                            $target instanceof Post => app(HidePostAction::class)->handle(auth()->user(), $target, $reason),
                            $target instanceof Comment => app(HideCommentAction::class)->handle(auth()->user(), $target, $reason),
                            default => throw new RuntimeException('Unsupported report target.'),
                        };
                    }),
            ]);
    }
}
