<?php

namespace App\Filament\Support;

use App\Filament\Pages\ProjectSettingsPage;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\RatingGroups\RatingGroupResource;
use App\Filament\Resources\Tags\TagResource;
use App\Support\Translations\ProjectContentSection;
use App\Support\Translations\ProjectTranslationUnit;

/**
 * The editor that holds a piece of project content's English text — Edit
 * source, wherever a translation is shown. Until the translation cutover the
 * same editors also still translate in place.
 */
final class TranslationSourceEditor
{
    public static function url(ProjectTranslationUnit $unit): string
    {
        return match ($unit->section) {
            ProjectContentSection::ProjectSettings, ProjectContentSection::StaticPages => ProjectSettingsPage::getUrl(),
            ProjectContentSection::Categories => CategoryResource::getUrl('edit', ['record' => $unit->recordId]),
            ProjectContentSection::Tags => TagResource::getUrl('edit', ['record' => $unit->recordId]),
            ProjectContentSection::RatingGroups => RatingGroupResource::getUrl('edit', ['record' => $unit->recordId]),
            // Options are edited on their group's page.
            ProjectContentSection::RatingOptions => RatingGroupResource::getUrl('edit', ['record' => $unit->parentId]),
        };
    }
}
