<?php

namespace App\Filament\Resources\Tags\Tables;

use App\Actions\Tags\DeleteTagAction;
use App\Exceptions\Tags\CannotDeleteTagException;
use App\Models\Tag;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TagsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('posts'))
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->color('gray'),
                TextColumn::make('posts_count')
                    ->label('Posts')
                    ->numeric()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('delete')
                    ->label('Delete')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (Tag $record): bool => auth()->user()?->can('delete', $record) ?? false)
                    ->requiresConfirmation()
                    ->modalDescription('Tags attached to posts cannot be deleted. Detach or merge them first.')
                    ->action(function (Tag $record): void {
                        try {
                            app(DeleteTagAction::class)->handle(auth()->user(), $record);
                        } catch (CannotDeleteTagException $e) {
                            [$title, $body] = $e->reason === CannotDeleteTagException::REASON_USED_BY_POSTS
                                ? ['Tag is used by posts', 'Detach or merge this tag before deleting it.']
                                : ['Cannot delete tag', 'You are not allowed to delete this tag.'];

                            Notification::make()
                                ->title($title)
                                ->body($body)
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Tag deleted')
                            ->success()
                            ->send();
                    }),
            ]);
    }
}
