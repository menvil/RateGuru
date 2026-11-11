<?php

namespace App\Filament\Support;

use App\Filament\Pages\ProjectSettingsPage;
use App\Filament\Resources\Categories\Pages\ManageCategories;
use App\Filament\Resources\Games\GameResource;
use App\Filament\Resources\RatingGroups\RatingGroupResource;
use App\Filament\Resources\Tags\Pages\ManageTags;
use App\Support\Translations\ProjectContentSection;
use App\Support\Translations\ProjectTranslationUnit;

/**
 * The editor that holds a piece of project content's English text — Edit
 * source, wherever a translation is shown. It edits English only; the
 * translations are Translation Center's.
 */
final class TranslationSourceEditor
{
    public static function url(ProjectTranslationUnit $unit): string
    {
        return match ($unit->section) {
            // A setting opens on its tab, a static page on its page.
            ProjectContentSection::ProjectSettings => ProjectSettingsPage::getUrl(array_key_exists($unit->key, ProjectSettingsPage::TEXT_FIELDS['identity']) ? [] : ['tab' => 'labels']),
            ProjectContentSection::StaticPages => ProjectSettingsPage::getUrl(['tab' => 'pages', 'page' => $unit->key]),
            // Categories and tags are edited in their list's drawer.
            ProjectContentSection::Categories => ManageCategories::getUrl(['edit' => $unit->recordId]),
            ProjectContentSection::Tags => ManageTags::getUrl(['edit' => $unit->recordId]),
            ProjectContentSection::RatingGroups => RatingGroupResource::getUrl('edit', ['record' => $unit->recordId]),
            // Options are edited on their group's page.
            ProjectContentSection::RatingOptions => RatingGroupResource::getUrl('edit', ['record' => $unit->parentId]),
            // A game's content opens where its editor shows it: the round, the set's results, the flow's step.
            ProjectContentSection::Games => GameResource::getUrl('edit', ['record' => $unit->recordId]),
            ProjectContentSection::GameRounds, ProjectContentSection::GameRoundOptions => GameResource::getUrl('content', ['record' => $unit->parentId, 'set' => $unit->location['set'] ?? null, 'round' => $unit->location['round'] ?? null]),
            ProjectContentSection::GameResultProfiles => GameResource::getUrl('content', ['record' => $unit->parentId, 'set' => $unit->location['set'] ?? null, 'view' => 'results']),
            ProjectContentSection::GameFlowSteps => GameResource::getUrl('flow', ['record' => $unit->parentId, 'flow' => $unit->location['flow'] ?? null, 'step' => $unit->location['step'] ?? null]),
        };
    }
}
