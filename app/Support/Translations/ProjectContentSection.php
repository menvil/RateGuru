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
    case Games = 'games';
    case GameRounds = 'game_rounds';
    case GameRoundOptions = 'game_round_options';
    case GameResultProfiles = 'game_result_profiles';
    case GameFlowSteps = 'game_flow_steps';

    public function label(): string
    {
        return match ($this) {
            self::ProjectSettings => 'Project Settings',
            self::StaticPages => 'Static Pages',
            self::Categories => 'Categories',
            self::RatingGroups => 'Rating Groups',
            self::RatingOptions => 'Rating Options',
            self::Tags => 'Tags',
            self::Games => 'Games',
            self::GameRounds => 'Game Rounds',
            self::GameRoundOptions => 'Game Round Options',
            self::GameResultProfiles => 'Game Results',
            self::GameFlowSteps => 'Game Flow Steps',
        };
    }

    /**
     * The kinds of content the repository ships for a project to start from —
     * the presets and the static page defaults. Games are only ever made in a
     * project.
     *
     * @return list<self>
     */
    public static function shippedByRepository(): array
    {
        return [self::ProjectSettings, self::StaticPages, self::Categories, self::RatingGroups, self::RatingOptions, self::Tags];
    }
}
