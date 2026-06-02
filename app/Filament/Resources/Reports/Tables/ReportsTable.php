<?php

namespace App\Filament\Resources\Reports\Tables;

use App\Actions\Comments\HideCommentAction;
use App\Actions\Moderation\BanUserAction;
use App\Actions\Moderation\HidePostAction;
use App\Actions\Reports\IgnoreReportAction;
use App\Actions\Reports\ResolveReportAction;
use App\Enums\PostStatus;
use App\Enums\ReportStatus;
use App\Enums\UserStatus;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

class ReportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Eager-load the polymorphic target and its author so the
            // hideTarget/banTargetAuthor visibility closures (which read
            // $record->target and its ->user) don't trigger per-row queries.
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['target' => function (MorphTo $morphTo): void {
                $morphTo->morphWith([
                    Post::class => ['user'],
                    Comment::class => ['post', 'user'],
                ]);
            }]))
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
                TextColumn::make('target_title')
                    ->label('Open target')
                    ->state(fn (Report $record): string => self::targetTitle($record))
                    ->limit(70)
                    ->wrap()
                    ->url(fn (Report $record): ?string => self::targetUrl($record))
                    ->openUrlInNewTab(),
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
                    ->visible(fn (Report $record): bool => $record->status === ReportStatus::Open
                        && auth()->user()?->can('resolve', $record) === true
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
                    ->visible(fn (Report $record): bool => $record->status === ReportStatus::Open
                        && auth()->user()?->can('ignore', $record) === true
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
                    ->visible(fn (Report $record): bool => ($record->target instanceof Post || $record->target instanceof Comment)
                        && auth()->user()?->can('hide', $record->target) === true
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
                Action::make('banTargetAuthor')
                    ->label('Ban target author')
                    ->icon('heroicon-o-user-minus')
                    ->color('danger')
                    ->visible(function (Report $record): bool {
                        // Authorization (admin-only + self / target-admin
                        // protection) is delegated to UserPolicy::ban; the
                        // not-already-banned check is a state guard.
                        $author = self::targetAuthor($record);

                        return $author !== null
                            && auth()->user()?->can('ban', $author) === true
                            && $author->status !== UserStatus::Banned;
                    })
                    ->schema([
                        Textarea::make('reason')
                            ->label('Reason')
                            ->maxLength(1000),
                    ])
                    ->requiresConfirmation()
                    ->action(function (Report $record, array $data): void {
                        $author = self::targetAuthor($record);

                        if ($author === null) {
                            throw new RuntimeException('Report target author not found.');
                        }

                        app(BanUserAction::class)->handle(
                            auth()->user(),
                            $author,
                            $data['reason'] ?? null,
                        );
                    }),
            ]);
    }

    /**
     * Resolve the User who authored the reported content (Post or Comment).
     * Returns null when the target row or its author has been deleted.
     */
    private static function targetAuthor(Report $report): ?User
    {
        $target = $report->target;

        return match (true) {
            $target instanceof Post, $target instanceof Comment => $target->user,
            default => null,
        };
    }

    private static function targetTitle(Report $report): string
    {
        $target = $report->target;

        return match (true) {
            $target instanceof Post => $target->title,
            $target instanceof Comment => $target->body,
            default => 'Target unavailable',
        };
    }

    private static function targetUrl(Report $report): ?string
    {
        $target = $report->target;

        return match (true) {
            $target instanceof Post && $target->status === PostStatus::Published => route('posts.show', $target),
            $target instanceof Comment && $target->post?->status === PostStatus::Published => route('posts.show', $target->post).'#comment-'.$target->id,
            default => null,
        };
    }
}
