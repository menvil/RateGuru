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
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Collection;

class PostsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // withTrashed: author-deleted posts stay reviewable as audit
            // history during retention. Only the well-formed author-deleted
            // shape (trashed + status Deleted) is listed among trashed rows
            // — a malformed legacy soft-deleted row would otherwise render
            // with status-matched moderation actions. Author restore is
            // never available in admin.
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->withoutGlobalScope(SoftDeletingScope::class)
                ->where(fn (Builder $q) => $q
                    ->whereNull('deleted_at')
                    ->orWhere('status', PostStatus::Deleted))
                ->with(['user', 'imageAsset']))
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
                    ->url(fn (Post $record): ?string => $record->status === PostStatus::Published
                        ? route('posts.show', $record)
                        : null)
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
                    // Author deletion reads as its own derived label, not
                    // as the raw Deleted enum value.
                    ->formatStateUsing(fn (PostStatus $state, Post $record): string => $record->isAuthorDeleted()
                        ? 'Deleted by author'
                        : ucfirst($state->value))
                    ->color(fn (PostStatus $state): string => match ($state) {
                        PostStatus::Pending => 'warning',
                        PostStatus::Published => 'success',
                        PostStatus::Hidden => 'gray',
                        PostStatus::Rejected => 'danger',
                        PostStatus::Draft => 'gray',
                        PostStatus::Deleted => 'gray',
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
                Filter::make('author_deleted')
                    ->label('Deleted by author')
                    ->query(fn (Builder $query) => $query
                        ->whereNotNull('deleted_at')
                        ->where('status', PostStatus::Deleted)),
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
