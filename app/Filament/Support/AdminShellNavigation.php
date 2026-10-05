<?php

namespace App\Filament\Support;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\LanguagesPage;
use App\Filament\Pages\MediaDiagnosticsPage;
use App\Filament\Pages\ProjectSettingsPage;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Comments\CommentResource;
use App\Filament\Resources\Posts\PostResource;
use App\Filament\Resources\RatingGroups\RatingGroupResource;
use App\Filament\Resources\Reports\ReportResource;
use App\Filament\Resources\Tags\TagResource;
use App\Filament\Resources\Users\UserResource;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Illuminate\Support\Str;

/**
 * What the Admin v2 shell draws, read from Filament's own navigation.
 *
 * Filament stays the source of truth: it decides which destinations exist,
 * which ones the signed-in user may see (each resource's and page's
 * canAccess()), their order, URLs, active state and any badge. This class only
 * reshapes that into plain data for the sidebar and topbar, adds the Admin v2
 * icon of each destination, and derives the breadcrumb. It runs no queries of
 * its own.
 *
 * @phpstan-type AdminShellNavigationItem array{key: string, label: string, url: ?string, icon: string, active: bool, newTab: bool, badge: ?string, badgeTone: string, badgeLabel: ?string}
 */
final class AdminShellNavigation
{
    /**
     * The x-admin.ui.icon of every destination, keyed by the resource or page
     * class Filament uses as the navigation item key.
     *
     * @var array<class-string, string>
     */
    public const ICONS = [
        Dashboard::class => 'layout-grid',
        PostResource::class => 'image',
        CommentResource::class => 'message-square',
        ReportResource::class => 'flag',
        UserResource::class => 'users',
        CategoryResource::class => 'folder',
        TagResource::class => 'tag',
        RatingGroupResource::class => 'star',
        LanguagesPage::class => 'globe',
        ProjectSettingsPage::class => 'settings-2',
        MediaDiagnosticsPage::class => 'hard-drive',
    ];

    /** Drawn for a destination that has no entry in ICONS yet; a test keeps that from shipping. */
    public const FALLBACK_ICON = 'circle';

    /**
     * The navigation sections the current user can see, in order.
     *
     * @return list<array{label: ?string, items: list<AdminShellNavigationItem>}>
     */
    public static function sections(): array
    {
        return array_values(array_map(
            fn (NavigationGroup $group): array => [
                'label' => $group->getLabel(),
                'items' => array_values(array_map(
                    fn (NavigationItem $item): array => self::item($item),
                    self::itemsOf($group),
                )),
            ],
            filament()->getNavigation(),
        ));
    }

    /**
     * Section › destination for the current page, without loading any record:
     * create and edit pages show their resource as the last step, linked back
     * to its list.
     *
     * @param  list<array{label: ?string, items: list<AdminShellNavigationItem>}>  $sections
     * @return list<array{label: string, url: ?string, current: bool}>
     */
    public static function breadcrumb(array $sections, string $currentUrl): array
    {
        foreach ($sections as $section) {
            foreach ($section['items'] as $item) {
                if (! $item['active']) {
                    continue;
                }

                $isCurrent = rtrim((string) $item['url'], '/') === rtrim($currentUrl, '/');

                return [
                    ...(filled($section['label']) ? [['label' => $section['label'], 'url' => null, 'current' => false]] : []),
                    ['label' => $item['label'], 'url' => $isCurrent ? null : $item['url'], 'current' => $isCurrent],
                ];
            }
        }

        return [];
    }

    public static function iconFor(string $key): string
    {
        return self::ICONS[$key] ?? self::FALLBACK_ICON;
    }

    /**
     * What a global search result is, from the category Filament files it
     * under (a resource's plural model label): its singular name and the
     * icon of the resource it belongs to.
     *
     * @return array{type: string, icon: string}
     */
    public static function searchCategory(string $category): array
    {
        foreach (filament()->getResources() as $resource) {
            if ($resource::getPluralModelLabel() === $category) {
                return [
                    'type' => Str::ucfirst($resource::getModelLabel()),
                    'icon' => self::iconFor($resource),
                ];
            }
        }

        return ['type' => Str::ucfirst($category), 'icon' => self::FALLBACK_ICON];
    }

    /**
     * The badge tone for a Filament navigation badge colour; anything that is
     * not a status colour is neutral.
     *
     * @param  string|array<int|string, mixed>|null  $color
     */
    public static function badgeTone(string|array|null $color): string
    {
        return match ($color) {
            'danger' => 'danger',
            'warning' => 'warning',
            'success' => 'success',
            'info' => 'info',
            default => 'neutral',
        };
    }

    /** @return AdminShellNavigationItem */
    private static function item(NavigationItem $item): array
    {
        $badge = $item->getBadge();
        $badgeTooltip = $item->getBadgeTooltip($badge);

        return [
            'key' => $item->getKey(),
            'label' => $item->getLabel(),
            'url' => $item->getUrl(),
            'icon' => self::iconFor($item->getKey()),
            'active' => $item->isActive(),
            'newTab' => $item->shouldOpenUrlInNewTab(),
            'badge' => filled($badge) ? (string) $badge : null,
            'badgeTone' => self::badgeTone($item->getBadgeColor($badge)),
            'badgeLabel' => filled($badgeTooltip) ? strip_tags((string) $badgeTooltip) : null,
        ];
    }

    /** @return array<NavigationItem> */
    private static function itemsOf(NavigationGroup $group): array
    {
        return collect($group->getItems())->all();
    }
}
