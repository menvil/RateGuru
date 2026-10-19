<?php

namespace App\Filament\Resources\StudioPlans\Pages;

use App\Actions\Studio\DeleteStudioPlanDraftAction;
use App\Actions\Studio\UpdateStudioPlanAction;
use App\Data\Studio\StudioPlanData;
use App\Exceptions\Studio\CannotManageStudioPlanException;
use App\Filament\Resources\StudioPlans\Concerns\EditsStudioPlan;
use App\Filament\Resources\StudioPlans\StudioPlanFormat;
use App\Filament\Resources\StudioPlans\StudioPlanResource;
use App\Models\StudioPlan;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

/**
 * One publication's editor in Admin v2, saved from the top bar: its texts and
 * settings through UpdateStudioPlanAction, then its photo through
 * SetStudioPlanImageAction or RemoveStudioPlanImageAction — each its own
 * atomic write, each handed the version the one before it left, so a save is
 * never in conflict with itself.
 *
 * The editor remembers the version it was drawn from (`lockVersion`): a save
 * after somebody else's is refused, keeps what was typed and says so, and
 * Reload draws the plan as it is now. A plan deleted meanwhile can no longer
 * be saved, and one past its Draft opens closed.
 */
final class EditStudioPlan extends Page
{
    use EditsStudioPlan;

    protected static string $resource = StudioPlanResource::class;

    protected string $view = 'filament.pages.studio-plan';

    // A screen drawn in Admin v2 brings its own header band and gutter.
    protected Width|string|null $maxContentWidth = 'rg-admin-main';

    #[Locked]
    public int $planId = 0;

    /** The plan's lock_version as the draft was drawn from it. */
    #[Locked]
    public int $lockVersion = 0;

    /** The plan's texts and settings as the draft was drawn from them, in one value. */
    #[Locked]
    public string $fingerprint = '';

    /** Whether somebody else saved the plan since the draft was drawn. */
    #[Locked]
    public bool $stale = false;

    /** Whether the plan was deleted meanwhile. */
    #[Locked]
    public bool $gone = false;

    /** Whether the plan is past its Draft, so nothing here changes it. */
    #[Locked]
    public bool $closed = false;

    /** Whether Delete draft is asking to be confirmed. */
    #[Locked]
    public bool $confirmingDelete = false;

    public function mount(int|string $record): void
    {
        $plan = is_numeric($record) ? StudioPlan::query()->find((int) $record) : null;

        abort_unless(StudioPlanResource::canAccess(), 403);
        abort_unless($plan !== null && StudioPlanResource::canEdit($plan), 404);

        $this->planId = $plan->id;
        $this->draw($plan);

        // Created a moment ago, on the page that redirected here.
        $created = session('rg-admin-toast');

        if (is_array($created) && is_string($created['message'] ?? null)) {
            $this->toast($created['message'], is_string($created['tone'] ?? null) ? $created['tone'] : 'success');
        }
    }

    public function getTitle(): string
    {
        $plan = StudioPlan::query()->find($this->planId);

        return $plan !== null ? StudioPlanFormat::title($plan) : 'Publication';
    }

    #[On('discard-studio-plan')]
    public function discard(): void
    {
        $plan = StudioPlan::query()->find($this->planId);

        $this->fillDraft(StudioPlanData::of($plan ?? new StudioPlan));
        $this->resetErrorBag();

        if ($plan === null) {
            $this->gone = true;
        }
    }

    /** Draws the plan as it is now: its version, its photo and the draft with them. */
    #[On('reload-studio-plan')]
    public function reload(): void
    {
        $plan = StudioPlan::query()->find($this->planId);

        if ($plan === null) {
            $this->gone = true;

            return;
        }

        $this->draw($plan);
        $this->resetErrorBag();
    }

    #[On('save-studio-plan')]
    public function save(): void
    {
        if ($this->gone || $this->closed) {
            return;
        }

        $plan = StudioPlan::query()->find($this->planId);

        if ($plan === null) {
            $this->refuse(CannotManageStudioPlanException::becausePlanIsGone());

            return;
        }

        $data = $this->validateDraft();
        $changed = self::fingerprintOf($data) !== $this->fingerprint;
        $version = $this->lockVersion;

        if ($changed) {
            try {
                app(UpdateStudioPlanAction::class)->handle($this->admin(), $plan, $data, $version);
            } catch (CannotManageStudioPlanException $exception) {
                $this->refuse($exception);

                return;
            }

            // The photo is saved against what this save has just written, so
            // the save is no conflict with itself.
            $version = $plan->lock_version;
            $this->settle($plan);
        }

        if (! $this->changesImage()) {
            $this->draw($plan);
            $this->toast($changed ? 'Draft saved' : 'Nothing to save');

            return;
        }

        try {
            $imageSaved = $this->saveImage($plan, $version);
        } catch (CannotManageStudioPlanException $exception) {
            $this->refuse($exception, $changed);

            return;
        }

        if (! $imageSaved) {
            // What this save wrote stands — settled above — and the photo is
            // all it lost; it stays in the draft to try again.
            $this->toast($changed ? 'Changes saved, but the photo wasn’t.' : 'The photo wasn’t saved.', 'error');

            return;
        }

        // The plan as this save left it: the actions keep the copy they were
        // handed in step with the row they wrote.
        $this->draw($plan);
        $this->toast('Draft saved');
    }

    public function askToDelete(): void
    {
        $this->confirmingDelete = ! $this->gone && ! $this->closed;
    }

    public function closeConfirmation(): void
    {
        $this->confirmingDelete = false;
    }

    /** Deletes the Draft as it was drawn, and goes back to the list. */
    public function delete(): void
    {
        $this->confirmingDelete = false;
        $plan = StudioPlan::query()->find($this->planId);

        if ($plan === null) {
            $this->refuse(CannotManageStudioPlanException::becausePlanIsGone(), false, 'deleted');

            return;
        }

        try {
            app(DeleteStudioPlanDraftAction::class)->handle($this->admin(), $plan, $this->lockVersion);
        } catch (CannotManageStudioPlanException $exception) {
            $this->refuse($exception, false, 'deleted');

            return;
        }

        // Nothing is left to save, so leaving is not leaving changes behind.
        $this->fillDraft(StudioPlanData::of($plan));
        $this->saved = self::savedValuesOf(StudioPlanData::of($plan));

        session()->flash('rg-admin-toast', ['message' => StudioPlanFormat::title($plan).' deleted', 'tone' => 'success']);

        $this->redirect(ListStudioPlans::getUrl());
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $plan = StudioPlan::query()->with(['imageAsset.variants', 'createdBy'])->find($this->planId);

        return [
            'creating' => false,
            'plan' => $plan === null ? null : [
                'id' => (int) $plan->id,
                'title' => StudioPlanFormat::title($plan),
                'state' => StudioPlanFormat::state($plan->status),
                'created' => $plan->created_at?->format('d M Y'),
                'createdBy' => $plan->createdBy !== null && ! $plan->createdBy->isTombstoned() ? (string) $plan->createdBy->username : null,
                'updated' => $plan->updated_at?->format('d M Y, H:i'),
            ],
            'readOnly' => $this->closed || $this->gone,
            ...$this->editorViewData($plan?->imageAsset),
        ];
    }

    /**
     * Says why nothing — or, once the texts were saved, only the photo — was
     * saved or deleted, keeping the draft as it is until Reload.
     */
    private function refuse(CannotManageStudioPlanException $exception, bool $restSaved = false, string $what = 'saved'): void
    {
        if ($exception->isAuthorUnavailable()) {
            $this->step = 'post';
            $this->addError('authorUserId', 'This persona can’t be chosen any more. Choose another one, or no author.');

            return;
        }

        if ($exception->isStale()) {
            $this->stale = true;
            $this->dispatch('rg-admin-toast',
                message: match (true) {
                    $restSaved => 'Changes saved, but someone else saved this draft meanwhile, so the photo wasn’t.',
                    $what === 'deleted' => 'Someone saved this draft after you opened it, so it wasn’t deleted.',
                    default => 'Someone saved this draft after you opened it, so nothing was saved.',
                },
                tone: 'error',
                action: ['label' => 'Reload', 'event' => 'reload-studio-plan', 'params' => []],
            );

            return;
        }

        $nothing = $restSaved ? 'the photo wasn’t saved' : "nothing was {$what}";

        if ($exception->isGone()) {
            $this->gone = true;
            $what === 'deleted'
                ? $this->toast('This draft was already deleted.', 'info')
                : $this->toast("This draft was deleted, so {$nothing}.", 'error');

            return;
        }

        if ($exception->isNotDraft()) {
            $this->closed = true;
            $this->toast("This plan is no longer a draft, so {$nothing}.", 'error');

            return;
        }

        $this->toast("You can’t manage publication plans any more, so {$nothing}.", 'error');
    }

    /** Draws the plan as it is: what is saved, its version, and the draft with them. */
    private function draw(StudioPlan $plan): void
    {
        $this->settle($plan);
        $this->fillDraft(StudioPlanData::of($plan));
        $this->stale = false;
        $this->closed = ! $plan->isDraft();
    }

    /** Takes what is saved now as what the draft was drawn from, keeping the draft as typed. */
    private function settle(StudioPlan $plan): void
    {
        $data = StudioPlanData::of($plan);

        $this->lockVersion = $plan->lock_version;
        $this->fingerprint = self::fingerprintOf($data);
        $this->saved = self::savedValuesOf($data);
    }
}
