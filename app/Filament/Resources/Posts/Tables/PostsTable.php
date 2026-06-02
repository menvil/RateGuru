<?php

namespace App\Filament\Resources\Posts\Tables;

use App\Actions\Moderation\ApprovePostAction;
use App\Actions\Moderation\HidePostAction;
use App\Actions\Moderation\RejectPostAction;
use App\Actions\Moderation\RestorePostAction;
use App\Enums\PostStatus;
use App\Models\Post;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PostsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user'))
            ->columns([
                ImageColumn::make('public_image_url')
                    ->label('Image')
                    ->getStateUsing(fn (Post $record): ?string => $record->public_image_url ? url($record->public_image_url) : null)
                    ->square()
                    ->defaultImageUrl(null)
                    ->url(fn (Post $record): ?string => $record->public_image_url ? url($record->public_image_url) : null)
                    ->openUrlInNewTab(),
                TextColumn::make('title')
                    ->label('Title')
                    ->searchable()
                    ->sortable()
                    ->limit(60)
                    ->url(fn (Post $record): string => route('posts.show', $record))
                    ->openUrlInNewTab(),
                TextColumn::make('user.username')
                    ->label('Author')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable()
                    ->color(fn (PostStatus $state): string => match ($state) {
                        PostStatus::Pending => 'warning',
                        PostStatus::Published => 'success',
                        PostStatus::Hidden => 'gray',
                        PostStatus::Rejected => 'danger',
                        PostStatus::Draft => 'gray',
                        PostStatus::Deleted => 'danger',
                    }),
                TextColumn::make('reports_count')
                    ->label('Reports')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Filter::make('pending')
                    ->label('Pending')
                    ->query(fn (Builder $query) => $query->where('status', PostStatus::Pending)),
                Filter::make('published')
                    ->label('Published')
                    ->query(fn (Builder $query) => $query->where('status', PostStatus::Published)),
                Filter::make('hidden')
                    ->label('Hidden')
                    ->query(fn (Builder $query) => $query->where('status', PostStatus::Hidden)),
                Filter::make('reported')
                    ->label('Reported')
                    ->query(fn (Builder $query) => $query->where('reports_count', '>', 0)),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Post $record): bool => $record->status === PostStatus::Pending)
                    ->requiresConfirmation()
                    ->action(function (Post $record): void {
                        app(ApprovePostAction::class)->handle(
                            auth()->user(),
                            $record
                        );
                    }),
                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Post $record): bool => $record->status === PostStatus::Pending)
                    ->schema([
                        Textarea::make('reason')
                            ->label('Reason')
                            ->maxLength(1000),
                    ])
                    ->requiresConfirmation()
                    ->action(function (Post $record, array $data): void {
                        app(RejectPostAction::class)->handle(
                            auth()->user(),
                            $record,
                            $data['reason'] ?? null,
                        );
                    }),
                Action::make('hide')
                    ->label('Hide')
                    ->icon('heroicon-o-eye-slash')
                    ->color('danger')
                    ->visible(fn (Post $record): bool => $record->status === PostStatus::Published)
                    ->schema([
                        Textarea::make('reason')
                            ->label('Reason')
                            ->maxLength(1000),
                    ])
                    ->requiresConfirmation()
                    ->action(function (Post $record, array $data): void {
                        app(HidePostAction::class)->handle(
                            auth()->user(),
                            $record,
                            $data['reason'] ?? null,
                        );
                    }),
                Action::make('restore')
                    ->label('Restore')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('success')
                    ->visible(fn (Post $record): bool => $record->status === PostStatus::Hidden)
                    ->schema([
                        Textarea::make('reason')
                            ->label('Reason')
                            ->maxLength(1000),
                    ])
                    ->requiresConfirmation()
                    ->action(function (Post $record, array $data): void {
                        app(RestorePostAction::class)->handle(
                            auth()->user(),
                            $record,
                            $data['reason'] ?? null,
                        );
                    }),
                Action::make('delete')
                    ->label('Delete')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (Post $record): bool => auth()->user()?->can('delete', $record) ?? false)
                    ->requiresConfirmation()
                    ->modalHeading('Delete post')
                    ->modalDescription('Soft-deletes the post. It can be restored from the database if needed.')
                    ->action(function (Post $record): void {
                        // Server-side authorization. visible() hides the
                        // button in the UI but the action endpoint is still
                        // reachable, so we re-check here before mutating.
                        abort_unless(auth()->user()?->can('delete', $record) === true, 403);

                        $record->delete();
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('bulkHide')
                    ->label('Hide selected')
                    ->icon('heroicon-o-eye-slash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->schema([
                        Textarea::make('reason')
                            ->label('Reason')
                            ->maxLength(1000),
                    ])
                    ->action(function (Collection $records, array $data): void {
                        $moderator = auth()->user();

                        $records->each(function (Post $record) use ($moderator, $data): void {
                            if ($record->status !== PostStatus::Published) {
                                return;
                            }

                            app(HidePostAction::class)->handle(
                                $moderator,
                                $record,
                                $data['reason'] ?? null,
                            );
                        });
                    }),
                BulkAction::make('bulkApprove')
                    ->label('Approve selected')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (Collection $records): void {
                        $moderator = auth()->user();

                        $records->each(function (Post $record) use ($moderator): void {
                            if ($record->status !== PostStatus::Pending) {
                                return;
                            }

                            app(ApprovePostAction::class)->handle(
                                $moderator,
                                $record
                            );
                        });
                    }),
            ]);
    }
}
