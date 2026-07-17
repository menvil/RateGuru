<?php

namespace App\Filament\Resources\Reports;

use App\Filament\Resources\Reports\Pages\ListReports;
use App\Filament\Resources\Reports\Tables\ReportsTable;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Report;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;

class ReportResource extends Resource
{
    protected static ?string $model = Report::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static ?string $navigationLabel = 'Reports';

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return null;
    }

    public static function table(Table $table): Table
    {
        return ReportsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        // Eager-load reporter and the polymorphic target so the table can
        // render `reporter.username` and dispatch hide/ban actions without
        // N+1 queries across the moderation queue. The target's author is
        // loaded per morph type via morphWith so the banTargetAuthor
        // visibility check (which reads $target->user) stays N+1-free.
        return parent::getEloquentQuery()->with([
            'reporter',
            'target' => function (Relation $relation): void {
                if ($relation instanceof MorphTo) {
                    $relation->morphWith([
                        Post::class => ['user'],
                        Comment::class => ['user'],
                    ]);
                }
            },
        ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReports::route('/'),
        ];
    }
}
