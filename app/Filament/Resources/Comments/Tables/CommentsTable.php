<?php

namespace App\Filament\Resources\Comments\Tables;

use App\Actions\Comments\HideCommentAction;
use App\Actions\Comments\RestoreCommentAction;
use App\Enums\CommentStatus;
use App\Enums\PostStatus;
use App\Models\Comment;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CommentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // withTrashed: author-deleted comments stay reviewable as
            // moderation/audit history (body visible to authorized staff);
            // they are labeled below and expose no actions at all.
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->withoutGlobalScope(SoftDeletingScope::class)
                ->with('post'))
            ->columns([
                TextColumn::make('body')
                    ->label('Comment')
                    ->limit(80)
                    ->wrap()
                    ->searchable()
                    ->url(fn (Comment $record): ?string => $record->post?->status === PostStatus::Published
                        ? route('posts.show', $record->post).'#comment-'.$record->id
                        : null)
                    ->openUrlInNewTab(),
                TextColumn::make('user.username')
                    ->label('Author')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('post.title')
                    ->label('Post')
                    ->limit(50)
                    ->searchable()
                    ->sortable()
                    ->placeholder('—')
                    ->url(fn (Comment $record): ?string => $record->post?->status === PostStatus::Published
                        ? route('posts.show', $record->post)
                        : null),
                TextColumn::make('reports_count')
                    ->label('Reports')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray'),
                // Derived lifecycle state, not the raw enum: author deletion
                // (deleted_at) and moderation hide (status) are orthogonal,
                // and an author-deleted row must read as exactly that even
                // if it was hidden first.
                TextColumn::make('lifecycle_state')
                    ->label('State')
                    ->badge()
                    ->state(fn (Comment $record): string => match (true) {
                        $record->isAuthorDeleted() => 'Deleted by author',
                        $record->isModeratorHidden() => 'Hidden by moderation',
                        default => 'Visible',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Hidden by moderation' => 'danger',
                        'Deleted by author' => 'gray',
                        default => 'success',
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Filter::make('hidden')
                    ->label('Hidden')
                    ->query(fn (Builder $query) => $query->whereNull('deleted_at')->where('status', CommentStatus::Hidden)),
                Filter::make('author_deleted')
                    ->label('Author deleted')
                    ->query(fn (Builder $query) => $query->whereNotNull('deleted_at')),
                Filter::make('reported')
                    ->label('Reported')
                    ->query(fn (Builder $query) => $query->where('reports_count', '>', 0)),
            ])
            ->recordActions([
                Action::make('hide')
                    ->label('Hide')
                    ->icon('heroicon-o-eye-slash')
                    ->color('danger')
                    ->visible(fn (Comment $record): bool => $record->status === CommentStatus::Visible
                        && auth()->user()?->can('hide', $record) === true
                    )
                    ->schema([
                        Textarea::make('reason')
                            ->label('Reason')
                            ->maxLength(1000),
                    ])
                    ->requiresConfirmation()
                    ->action(function (Comment $record, array $data): void {
                        app(HideCommentAction::class)->handle(
                            auth()->user(),
                            $record,
                            $data['reason'] ?? null,
                        );
                    }),
                Action::make('restore')
                    ->label('Restore')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('success')
                    ->visible(fn (Comment $record): bool => $record->status === CommentStatus::Hidden
                        && auth()->user()?->can('restore', $record) === true
                    )
                    ->schema([
                        Textarea::make('reason')
                            ->label('Reason')
                            ->maxLength(1000),
                    ])
                    ->requiresConfirmation()
                    ->action(function (Comment $record, array $data): void {
                        app(RestoreCommentAction::class)->handle(
                            auth()->user(),
                            $record,
                            $data['reason'] ?? null,
                        );
                    }),
                // No delete action here on purpose: comment deletion is an
                // authored-content decision that belongs to the author alone
                // (CommentPolicy::delete is owner-only). Moderation acts
                // through hide/restore; permanent moderation purge belongs
                // to a later retention policy.
            ]);
    }
}
