<?php

namespace App\Filament\Resources\StudioPlans;

use App\Filament\Resources\StudioPlans\Pages\CreateStudioPlan;
use App\Filament\Resources\StudioPlans\Pages\EditStudioPlan;
use App\Filament\Resources\StudioPlans\Pages\ListStudioPlans;
use App\Filament\Support\AdminNavigationGroup;
use App\Models\StudioPlan;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

/**
 * Content Studio's Publications & plans: the list (ListStudioPlans), a new
 * publication (CreateStudioPlan) and a publication's editor
 * (EditStudioPlan), drawn in Admin v2 over StudioPlan. A plan is a draft of a
 * post until its editor's Review and confirm approves it
 * (ApproveStudioPlanAction); its actions then run, and are managed, in the
 * Action queue (StudioActionQueue).
 *
 * Only an active administrator gets here (`manage-studio-plans`): the
 * navigation item, every page and every Livewire request on them ask, and
 * the Content Studio actions ask again on the locked rows. Every write goes
 * through those actions; the pages hold no rule of their own.
 */
class StudioPlanResource extends Resource
{
    protected static ?string $model = StudioPlan::class;

    protected static ?string $slug = 'studio-plans';

    protected static ?string $modelLabel = 'publication plan';

    protected static ?string $pluralModelLabel = 'publication plans';

    protected static ?string $navigationLabel = 'Publications & plans';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNewspaper;

    // Before Personas, as the section lists them.
    protected static ?int $navigationSort = 5;

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): ?string
    {
        return AdminNavigationGroup::CONTENT_STUDIO;
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manage-studio-plans') === true;
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    public static function canCreate(): bool
    {
        return static::canAccess();
    }

    /**
     * Any plan opens in the editor for whoever may manage plans; one past its
     * Draft opens closed, saying why it can no longer be changed.
     */
    public static function canEdit(Model $record): bool
    {
        return static::canAccess() && $record instanceof StudioPlan;
    }

    /** A Draft is deleted only from its own pages, through DeleteStudioPlanDraftAction. */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStudioPlans::route('/'),
            'create' => CreateStudioPlan::route('/create'),
            'edit' => EditStudioPlan::route('/{record}/edit'),
        ];
    }
}
