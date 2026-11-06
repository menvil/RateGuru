<?php

namespace App\Support\Translations;

/**
 * The kinds of project content that carry a translation per language — what
 * the database holds for this project, as opposed to the application catalogs
 * a release ships.
 */
enum ProjectContentSection: string
{
    case ProjectSettings = 'project_settings';
    case StaticPages = 'static_pages';
    case Categories = 'categories';
    case RatingGroups = 'rating_groups';
    case RatingOptions = 'rating_options';
    case Tags = 'tags';

    public function label(): string
    {
        return match ($this) {
            self::ProjectSettings => 'Project Settings',
            self::StaticPages => 'Static Pages',
            self::Categories => 'Categories',
            self::RatingGroups => 'Rating Groups',
            self::RatingOptions => 'Rating Options',
            self::Tags => 'Tags',
        };
    }
}
