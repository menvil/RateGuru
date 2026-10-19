<?php

namespace App\Filament\Resources\StudioPlans\Pages;

use App\Actions\Studio\CreateStudioPlanAction;
use App\Data\Studio\StudioPlanData;
use App\Exceptions\Studio\CannotManageStudioPlanException;
use App\Filament\Resources\StudioPlans\Concerns\EditsStudioPlan;
use App\Filament\Resources\StudioPlans\StudioPlanFormat;
use App\Filament\Resources\StudioPlans\StudioPlanResource;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

/**
 * A new publication, in Admin v2: the publication editor before anything is
 * saved. No plan exists until Create draft in the top bar — which works on an
 * empty editor too, since a Draft may be as incomplete as it is — makes one
 * through CreateStudioPlanAction; a photo chosen here is given to it right
 * after, through SetStudioPlanImageAction, and the new draft's editor opens.
 */
final class CreateStudioPlan extends Page
{
    use EditsStudioPlan;

    protected static string $resource = StudioPlanResource::class;

    protected string $view = 'filament.pages.studio-plan';

    // A screen drawn in Admin v2 brings its own header band and gutter.
    protected Width|string|null $maxContentWidth = 'rg-admin-main';

    protected static ?string $title = 'New publication';

    /** The plan this page made, so a Save sent twice never makes a second one. */
    #[Locked]
    public ?int $createdId = null;

    public function mount(): void
    {
        $empty = new StudioPlanData;

        $this->saved = self::savedValuesOf($empty);
        $this->fillDraft($empty);
    }

    #[On('discard-studio-plan')]
    public function discard(): void
    {
        $this->fillDraft(new StudioPlanData);
        $this->resetErrorBag();
    }

    #[On('save-studio-plan')]
    public function save(): void
    {
        if ($this->createdId !== null) {
            $this->redirect(EditStudioPlan::getUrl(['record' => $this->createdId]));

            return;
        }

        $data = $this->validateDraft();

        try {
            $plan = app(CreateStudioPlanAction::class)->handle($this->admin(), $data);
        } catch (CannotManageStudioPlanException $exception) {
            if ($exception->isAuthorUnavailable()) {
                $this->step = 'post';
                $this->addError('authorUserId', 'This persona can’t be chosen any more. Choose another one, or no author.');
            } else {
                $this->toast('You can’t manage publication plans any more, so nothing was created.', 'error');
            }

            return;
        }

        $this->createdId = $plan->id;
        $imageSaved = true;

        if ($this->changesImage()) {
            try {
                $imageSaved = $this->saveImage($plan, $plan->lock_version);
            } catch (CannotManageStudioPlanException) {
                // Changed, deleted or put out of reach the moment it was made:
                // its editor says which.
                $imageSaved = false;
            }
        }

        // Everything on the page is saved now, so leaving it for the editor
        // is not leaving unsaved changes behind.
        $this->saved = self::savedValuesOf(StudioPlanData::of($plan));
        $this->fillDraft(StudioPlanData::of($plan));

        session()->flash('rg-admin-toast', $imageSaved
            ? ['message' => StudioPlanFormat::title($plan).' created', 'tone' => 'success']
            : ['message' => StudioPlanFormat::title($plan).' created, but its photo wasn’t saved. Choose it again.', 'tone' => 'error']);

        $this->redirect(EditStudioPlan::getUrl(['record' => $plan->id]));
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'creating' => true,
            'plan' => null,
            'readOnly' => false,
            ...$this->editorViewData(null),
        ];
    }
}
