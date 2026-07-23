<?php

namespace App\Filament\Resources\Categories\Tables;

use App\Actions\Categories\DeleteCategoryAction;
use App\Exceptions\Categories\CannotDeleteCategoryException;
use App\Models\Category;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('posts'))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('slug')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->color('gray'),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('sort_order')
                    ->label('Sort')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('posts_count')
                    ->label('Posts')
                    ->numeric()
                    ->sortable(),
            ])
            ->defaultSort('sort_order')
            ->recordActions([
                EditAction::make(),
                Action::make('delete')
                    ->label('Delete')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(fn (Category $record): string => ($record->posts_count ?? 0) > 0
                        ? 'This category is assigned to posts and cannot be deleted. Deactivate it instead.'
                        : 'Permanently delete this unused category?')
                    ->action(function (Category $record): void {
                        try {
                            app(DeleteCategoryAction::class)->handle(auth()->user(), $record);
                        } catch (CannotDeleteCategoryException $exception) {
                            Notification::make()
                                ->title('Cannot delete category')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Category deleted')
                            ->success()
                            ->send();
                    }),
            ]);
    }
}
