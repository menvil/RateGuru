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
            ->modifyQueryUsing(self::withPostCounts(...))
            // The curated order, as a DEFAULT sort rather than part of the base
            // query. Applied through modifyQueryUsing it was not a default at
            // all: sort_order plus the unique id leaves no ties, so the ORDER BY
            // Filament appends for the column an administrator clicked could
            // never change a single row's position, and all five sortable
            // columns here were decorative. As a default sort it is what you get
            // until you ask for something else, which is what it was meant to be.
            //
            // Through the model scope rather than spelled out again here: what
            // "ordered" means for a category is Category::scopeOrdered's to say,
            // and a second copy of it would drift.
            ->defaultSort(self::curatedOrder(...))
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

    /**
     * @param  Builder<Category>  $query
     * @return Builder<Category>
     */
    private static function withPostCounts(Builder $query): Builder
    {
        return $query->withCount('posts');
    }

    /**
     * A named method rather than a closure in the table definition, so the
     * generic annotation is somewhere to put: a bare `Builder` does not carry
     * the model, and the scope would not resolve.
     *
     * @param  Builder<Category>  $query
     * @return Builder<Category>
     */
    private static function curatedOrder(Builder $query): Builder
    {
        return $query->ordered();
    }
}
