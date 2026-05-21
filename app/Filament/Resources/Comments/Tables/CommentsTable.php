<?php

namespace App\Filament\Resources\Comments\Tables;

use App\Actions\Comments\DeleteCommentAction;
use App\Actions\Comments\HideCommentAction;
use App\Actions\Comments\RestoreCommentAction;
use App\Enums\CommentStatus;
use App\Filament\Resources\Posts\PostResource;
use App\Models\Comment;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CommentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('body')
                    ->label('Comment')
                    ->limit(80)
                    ->wrap()
                    ->searchable(),
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
                    ->url(fn (Comment $record): ?string => $record->post
                        ? PostResource::getUrl('index', ['tableSearch' => $record->post->title])
                        : null),
                TextColumn::make('reports_count')
                    ->label('Reports')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable()
                    ->color(fn (CommentStatus $state): string => match ($state) {
                        CommentStatus::Visible => 'success',
                        CommentStatus::Hidden => 'danger',
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Filter::make('hidden')
                    ->label('Hidden')
                    ->query(fn (Builder $query) => $query->where('status', CommentStatus::Hidden)),
                Filter::make('reported')
                    ->label('Reported')
                    ->query(fn (Builder $query) => $query->where('reports_count', '>', 0)),
            ])
            ->recordActions([
                Action::make('hide')
                    ->label('Hide')
                    ->icon('heroicon-o-eye-slash')
                    ->color('danger')
                    ->visible(fn (Comment $record): bool =>
                        $record->status === CommentStatus::Visible
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
                    ->visible(fn (Comment $record): bool =>
                        $record->status === CommentStatus::Hidden
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
                Action::make('delete')
                    ->label('Delete')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    // Deliberate UI scoping: in the moderation table only
                    // admins delete; moderators use hide/restore. This is
                    // intentionally stricter than CommentPolicy::delete
                    // (owner|admin), which still governs the action itself.
                    ->visible(fn (): bool => auth()->user()?->isAdmin() === true)
                    ->requiresConfirmation()
                    ->action(function (Comment $record): void {
                        app(DeleteCommentAction::class)->handle(
                            auth()->user(),
                            $record,
                        );
                    }),
            ]);
    }
}
