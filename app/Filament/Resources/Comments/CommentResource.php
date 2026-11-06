<?php

namespace App\Filament\Resources\Comments;

use App\Enums\CommentStatus;
use App\Filament\Resources\Comments\Pages\ListComments;
use App\Filament\Support\AdminNavigationGroup;
use App\Models\Comment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

/**
 * Comments in the admin: one screen, ListComments, drawn in Admin v2. The
 * resource keeps the route, the navigation item with its count of reported
 * comments still visible, and global search, whose results open the comment
 * in that list.
 */
class CommentResource extends Resource
{
    protected static ?string $model = Comment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $navigationLabel = 'Comments';

    protected static ?string $recordTitleAttribute = 'body';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return AdminNavigationGroup::MODERATION;
    }

    /** What waits for a moderator: reported comments still visible, none when there are none. */
    public static function getNavigationBadge(): ?string
    {
        $reported = Comment::query()->where('status', CommentStatus::Visible)->where('reports_count', '>', 0)->count();

        return $reported > 0 ? (string) $reported : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Reported comments still visible';
    }

    public static function getGlobalSearchResultUrl(Model $record): ?string
    {
        return ListComments::getUrl(['q' => '#'.$record->getKey()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListComments::route('/'),
        ];
    }
}
