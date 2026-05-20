<?php

namespace App\Filament\Resources\Comments\Tables;

use App\Enums\CommentStatus;
use App\Filament\Resources\Posts\PostResource;
use App\Models\Comment;
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
            ->recordActions([]);
    }
}
