<?php

namespace App\Filament\Resources\Comments\Tables;

use App\Filament\Resources\Posts\PostResource;
use App\Models\Comment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

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
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([])
            ->recordActions([]);
    }
}
