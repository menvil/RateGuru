<?php

namespace App\Filament\Resources\Posts;

use App\Enums\PostStatus;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Filament\Support\AdminNavigationGroup;
use App\Models\Post;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Posts in the admin: one screen, ListPosts, drawn in Admin v2. The resource
 * keeps the route, the navigation item with its count of posts waiting for
 * review, and global search, whose results open the post in that list.
 */
class PostResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Posts';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return AdminNavigationGroup::MODERATION;
    }

    /** What waits for a moderator: the posts pending review, none when the queue is clear. */
    public static function getNavigationBadge(): ?string
    {
        $pending = Post::query()->where('status', PostStatus::Pending)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Posts waiting for review';
    }

    public static function getGlobalSearchResultUrl(Model $record): ?string
    {
        return ListPosts::getUrl(['status' => 'all', 'q' => '#'.$record->getKey()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPosts::route('/'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
