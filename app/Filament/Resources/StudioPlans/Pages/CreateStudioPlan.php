<?php

namespace App\Filament\Resources\StudioPlans\Pages;

use App\Actions\Studio\CreateStudioPlanAction;
use App\Actions\Studio\UpdateStudioPlanAction;
use App\Data\Studio\StudioPlanData;
use App\Exceptions\Studio\CannotManageStudioPlanException;
use App\Filament\Resources\StudioPlans\Concerns\EditsStudioPlan;
use App\Filament\Resources\StudioPlans\StudioPlanFormat;
use App\Filament\Resources\StudioPlans\StudioPlanResource;
use App\Models\StudioPlan;
use App\Queries\Personas\PersonasQuery;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

/**
 * A new publication, in Admin v2: the publication editor before anything is
 * saved. Create draft in the top bar — which works on an empty editor too,
 * since a Draft may be as incomplete as it is — makes the plan through
 * CreateStudioPlanAction, and its editor opens.
 *
 * The page starts with an active Persona drawn at random as the author
 * (`startAuthorId`), to change or clear; it is where the page starts from,
 * not a change, and Discard comes back to it.
 *
 * A photo needs a plan to be kept on, so the first one chosen here makes a
 * Draft first — empty but for that author — once, and is stored on it
 * straight away (EditsStudioPlan).
 * The page goes on with that plan (`planId`): later photos replace the one it
 * has, the texts typed stay as typed and unsaved, and Save draft — the top
 * bar's button says so from then on — writes them to that plan through
 * UpdateStudioPlanAction rather than making another one.
 */
final class CreateStudioPlan extends Page
{
    use EditsStudioPlan;

    /**
     * Flashed for the editor a save here opens: the plan, the step and tab
     * the page was on, and whether Generate tree or Generate suggestions
     * asked for the save — read once, so a reload neither moves the page nor
     * generates again.
     */
    public const RESUME = 'rg-admin-studio-resume';

    /** The author the page started with, drawn at random: what it comes back to and what a photo's Draft is made with. */
    #[Locked]
    public ?int $startAuthorId = null;

    protected static string $resource = StudioPlanResource::class;

    protected string $view = 'filament.pages.studio-plan';

    // A screen drawn in Admin v2 brings its own header band and gutter.
    protected Width|string|null $maxContentWidth = 'rg-admin-main';

    protected static ?string $title = 'New publication';

    public function mount(): void
    {
        $this->startAuthorId = app(PersonasQuery::class)->randomActive(1)->first()?->id;
        $start = new StudioPlanData(authorUserId: $this->startAuthorId);

        $this->saved = self::savedValuesOf($start);
        $this->fillDraft($start);
    }

    #[On('discard-studio-plan')]
    public function discard(): void
    {
        $this->fillDraft(new StudioPlanData(authorUserId: $this->startAuthorId));
        $this->resetErrorBag();
    }

    /** The editor of the plan a photo made here: the place to see it as others will. */
    #[On('reload-studio-plan')]
    public function reload(): void
    {
        if ($this->planId !== 0) {
            $this->redirect(EditStudioPlan::getUrl(['record' => $this->planId]));
        }
    }

    /**
     * Makes the plan with what the editor holds — or, once a photo has made
     * one, writes what the editor holds to it — and opens its editor on the
     * step this page was on (RESUME), generating there when Generate tree
     * asked for the save (`then` `generate`), or Generate suggestions did
     * (`then` `suggest`). Sent twice, it makes one plan.
     */
    #[On('save-studio-plan')]
    public function save(mixed $then = null): void
    {
        $plan = $this->planId !== 0 ? StudioPlan::query()->find($this->planId) : null;

        if ($this->planId !== 0 && $plan === null) {
            $this->toast('This draft was deleted, so nothing was saved.', 'error');

            return;
        }

        $data = $this->validateDraft();
        $created = $plan === null;

        try {
            if ($plan === null) {
                $plan = app(CreateStudioPlanAction::class)->handle($this->admin(), $data);
            } else {
                app(UpdateStudioPlanAction::class)->handle($this->admin(), $plan, $data, $this->lockVersion);
            }
        } catch (CannotManageStudioPlanException $exception) {
            $this->refuse($exception, $created ? 'nothing was created' : 'nothing was saved');

            return;
        }

        $this->planId = $plan->id;
        $this->lockVersion = $plan->lock_version;

        // Everything on the page is saved now, so leaving it for the editor
        // is not leaving unsaved changes behind.
        $this->saved = self::savedValuesOf(StudioPlanData::of($plan));
        $this->fillDraft(StudioPlanData::of($plan));

        session()->flash('rg-admin-toast', ['message' => StudioPlanFormat::title($plan).($created ? ' created' : ' saved'), 'tone' => 'success']);
        session()->flash(self::RESUME, ['plan' => $plan->id, 'step' => $this->step, 'postTab' => $this->postTab, 'generate' => $then === 'generate', 'suggest' => $then === 'suggest']);

        $this->redirect(EditStudioPlan::getUrl(['record' => $plan->id]));
    }

    /** A new publication is approved from its own editor, once its draft is saved. */
    public function askToApprove(): void
    {
        $this->toast('Save the draft before approving it.', 'info');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $plan = $this->planId !== 0 ? StudioPlan::query()->with('imageAsset.variants')->find($this->planId) : null;

        return [
            'creating' => true,
            'plan' => null,
            'readOnly' => false,
            ...$this->editorViewData($plan),
        ];
    }

    /** Whether the plan's texts and settings are the ones this page last saw saved: the Draft it started as, until Save draft. */
    protected function holdsSaved(StudioPlan $plan): bool
    {
        return self::savedValuesOf(StudioPlanData::of($plan)) === $this->saved;
    }

    /** The plan a photo made here, or — for the first one — an empty Draft made for it now. */
    protected function photoPlan(): ?StudioPlan
    {
        if ($this->planId !== 0) {
            $plan = StudioPlan::query()->find($this->planId);

            if ($plan === null) {
                $this->refusePhoto(CannotManageStudioPlanException::becausePlanIsGone());
            }

            return $plan;
        }

        try {
            $plan = app(CreateStudioPlanAction::class)->handle($this->admin(), new StudioPlanData(authorUserId: $this->startAuthorId));
        } catch (CannotManageStudioPlanException $exception) {
            $this->refusePhoto($exception);

            return null;
        }

        $this->planId = $plan->id;
        $this->lockVersion = $plan->lock_version;

        return $plan;
    }

    protected function refusePhoto(CannotManageStudioPlanException $exception, string $what = 'saved'): void
    {
        $this->refuse($exception, "the photo wasn’t {$what}");
    }

    /** Says why nothing, or the photo, was written; a draft changed by somebody else is theirs to see in its editor. */
    private function refuse(CannotManageStudioPlanException $exception, string $nothing): void
    {
        if ($exception->isAuthorUnavailable()) {
            $this->step = 'post';
            $this->addError('authorUserId', 'This persona can’t be chosen any more. Choose another one, or no author.');

            return;
        }

        if ($exception->isStale()) {
            $this->dispatch('rg-admin-toast',
                message: "Someone saved this draft meanwhile, so {$nothing}.",
                tone: 'error',
                action: ['label' => 'Open it', 'event' => 'reload-studio-plan', 'params' => []],
            );

            return;
        }

        $this->toast(match (true) {
            $exception->isGone() => "This draft was deleted, so {$nothing}.",
            $exception->isNotDraft() => "This plan is no longer a draft, so {$nothing}.",
            default => "You can’t manage publication plans any more, so {$nothing}.",
        }, 'error');
    }
}
