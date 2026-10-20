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
 * One publication's editor in Admin v2: its texts and settings saved from the
 * top bar through UpdateStudioPlanAction, its photo stored, replaced or taken
 * off the moment it is chosen (EditsStudioPlan) — each its own atomic write,
 * handed the version the one before it left, so neither is in conflict with
 * the other.
 *
 * The editor remembers the version it last read or wrote (`lockVersion`): a
 * save or a photo after somebody else's save is refused, keeps what was typed
 * and says so, and Reload draws the plan as it is now. A plan deleted
 * meanwhile can no longer be saved, and one past its Draft opens closed.
 */
final class EditStudioPlan extends Page
{
    use EditsStudioPlan;

    protected static string $resource = StudioPlanResource::class;

    protected string $view = 'filament.pages.studio-plan';

    // A screen drawn in Admin v2 brings its own header band and gutter.
    protected Width|string|null $maxContentWidth = 'rg-admin-main';

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

    /**
     * Puts back what is saved: the plan as it is now, with its version and
     * what the draft is compared to, so nothing on the page is drawn from one
     * save and compared to another. A plan deleted meanwhile leaves an empty
     * draft that can no longer be saved.
     */
    #[On('discard-studio-plan')]
    public function discard(): void
    {
        $plan = StudioPlan::query()->find($this->planId);
        $this->resetErrorBag();

        if ($plan === null) {
            $this->fillDraft(new StudioPlanData);
            $this->gone = true;

            return;
        }

        $this->draw($plan);
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

        if (self::fingerprintOf($data) === $this->fingerprint) {
            $this->draw($plan);
            $this->toast('Nothing to save');

            return;
        }

        try {
            app(UpdateStudioPlanAction::class)->handle($this->admin(), $plan, $data, $this->lockVersion);
        } catch (CannotManageStudioPlanException $exception) {
            $this->refuse($exception);

            return;
        }

        // The plan as this save left it: the action keeps the copy it was
        // handed in step with the row it wrote — the version said to the
        // discussion's editor on the page, the rest drawn from the plan.
        $this->movedVersion($plan->lock_version);
        $this->draw($plan);
        $this->toast('Draft saved');
    }

    /** Somebody else saved the plan: what this editor holds is out of date until Reload. */
    protected function versionMovedElsewhere(): void
    {
        $this->stale = true;
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
            $this->refuse(CannotManageStudioPlanException::becausePlanIsGone(), 'deleted');

            return;
        }

        try {
            app(DeleteStudioPlanDraftAction::class)->handle($this->admin(), $plan, $this->lockVersion);
        } catch (CannotManageStudioPlanException $exception) {
            $this->refuse($exception, 'deleted');

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
            ...$this->editorViewData($plan),
        ];
    }

    protected function photoPlan(): ?StudioPlan
    {
        $plan = StudioPlan::query()->find($this->planId);

        if ($plan === null) {
            $this->refusePhoto(CannotManageStudioPlanException::becausePlanIsGone());
        }

        return $plan;
    }

    protected function refusePhoto(CannotManageStudioPlanException $exception, string $what = 'saved'): void
    {
        $this->refuse($exception, $what, 'the photo');
    }

    /**
     * Says why nothing — or the photo — was saved, removed or deleted,
     * keeping the draft as it is until Reload.
     */
    private function refuse(CannotManageStudioPlanException $exception, string $what = 'saved', ?string $subject = null): void
    {
        if ($exception->isAuthorUnavailable()) {
            $this->step = 'post';
            $this->addError('authorUserId', 'This persona can’t be chosen any more. Choose another one, or no author.');

            return;
        }

        $nothing = $subject !== null ? "{$subject} wasn’t {$what}" : "nothing was {$what}";

        if ($exception->isStale()) {
            $this->stale = true;
            $this->dispatch('rg-admin-toast',
                message: $what === 'deleted' && $subject === null
                    ? 'Someone saved this draft after you opened it, so it wasn’t deleted.'
                    : "Someone saved this draft after you opened it, so {$nothing}.",
                tone: 'error',
                action: ['label' => 'Reload', 'event' => 'reload-studio-plan', 'params' => []],
            );

            return;
        }

        if ($exception->isGone()) {
            $this->gone = true;
            $what === 'deleted' && $subject === null
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
