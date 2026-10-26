<?php

namespace App\Filament\Resources\StudioPlans\Pages;

use App\Actions\Studio\ApproveStudioPlanAction;
use App\Actions\Studio\DeleteStudioPlanDraftAction;
use App\Actions\Studio\UpdateStudioPlanAction;
use App\Data\Studio\StudioPlanData;
use App\Enums\StudioPlanApprovalRejection;
use App\Exceptions\Studio\CannotApproveStudioPlanException;
use App\Exceptions\Studio\CannotManageStudioPlanException;
use App\Filament\Resources\StudioPlans\Concerns\EditsStudioPlan;
use App\Filament\Resources\StudioPlans\StudioPlanFormat;
use App\Filament\Resources\StudioPlans\StudioPlanResource;
use App\Livewire\Admin\StudioPlanActionPlan;
use App\Livewire\Admin\StudioPlanDiscussionEditor;
use App\Livewire\Admin\StudioPlanTextSuggestionsCard;
use App\Models\StudioPlan;
use App\Support\Studio\StudioExecutionSwitch;
use App\Support\Studio\StudioPlanApprovalPreflight;
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
 * meanwhile can no longer be saved, and one past its Draft opens closed, on
 * its Publication step, where its actions are followed as they run.
 *
 * Review and confirm, on the Publication step, approves the plan
 * (ApproveStudioPlanAction) — only as it is saved, at the version this editor
 * holds, with nothing unsaved on the page and nothing the approval preflight
 * finds in the way — once its confirmation, which says what will be written
 * and how it will run, is confirmed.
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

    /** Whether Review and confirm is asking to be confirmed. */
    #[Locked]
    public bool $confirmingApproval = false;

    public function mount(int|string $record): void
    {
        $plan = is_numeric($record) ? StudioPlan::query()->find((int) $record) : null;

        abort_unless(StudioPlanResource::canAccess(), 403);
        abort_unless($plan !== null && StudioPlanResource::canEdit($plan), 404);

        $this->planId = $plan->id;
        $this->draw($plan);

        // A plan past its Draft is followed where its actions are.
        if ($this->closed) {
            $this->step = 'publication';
        }

        // Created a moment ago, on the page that redirected here.
        $created = session('rg-admin-toast');

        if (is_array($created) && is_string($created['message'] ?? null)) {
            $this->toast($created['message'], is_string($created['tone'] ?? null) ? $created['tone'] : 'success');
        }

        // Saved on the new publication's page: on where it was, and on with
        // Generate tree, or Generate suggestions, when that saved it.
        $resume = session(CreateStudioPlan::RESUME);

        if (is_array($resume) && (int) ($resume['plan'] ?? 0) === $plan->id) {
            $this->goToStep($resume['step'] ?? null);
            $this->showPostTab($resume['postTab'] ?? null);

            if (($resume['generate'] ?? false) === true) {
                $this->dispatch(StudioPlanDiscussionEditor::GENERATE_EVENT, planId: $plan->id);
            }

            if (($resume['suggest'] ?? false) === true) {
                $this->dispatch(StudioPlanTextSuggestionsCard::GENERATE_EVENT, planId: $plan->id, authorUserId: $plan->author_user_id);
            }
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
        $this->saidReloaded();
    }

    /**
     * Draws the plan as it is now: its version, its photo and the draft with
     * them — and says so to the discussion's editor on the page, which draws
     * its discussion again too, so one Reload brings the whole page up to date.
     */
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
        $this->saidReloaded();
    }

    /**
     * Save draft — or Generate tree, clicked with the post changed (`then`
     * `generate`), or Generate suggestions, clicked with another author
     * chosen (`then` `suggest`): the discussion's editor, or the suggestions'
     * card, is told to generate once the post is saved, or found saved
     * already.
     */
    #[On('save-studio-plan')]
    public function save(mixed $then = null): void
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
            $this->generateAfterSave($then);

            if ($then !== 'generate' && $then !== 'suggest') {
                $this->toast('Nothing to save');
            }

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
        $this->generateAfterSave($then);
    }

    /** Generate tree, or Generate suggestions, waited for the post to be saved: it goes on now. */
    private function generateAfterSave(mixed $then): void
    {
        if ($then === 'generate') {
            $this->dispatch(StudioPlanDiscussionEditor::GENERATE_EVENT, planId: $this->planId);
        }

        if ($then === 'suggest') {
            $this->dispatch(StudioPlanTextSuggestionsCard::GENERATE_EVENT, planId: $this->planId, authorUserId: $this->authorUserId !== '' ? (int) $this->authorUserId : null);
        }
    }

    /** Somebody else saved the plan: what this editor holds is out of date until Reload. */
    protected function versionMovedElsewhere(): void
    {
        $this->stale = true;
    }

    /** The plan's version was taken with the post as drawn: nothing here is out of date — said, if it was said to be. */
    protected function versionTaken(): bool
    {
        $wasStale = $this->stale;
        $this->stale = false;

        return $wasStale;
    }

    /** Whether the plan's texts and settings are the ones the draft was drawn from: its fingerprint. */
    protected function holdsSaved(StudioPlan $plan): bool
    {
        return hash_equals($this->fingerprint, self::fingerprintOf(StudioPlanData::of($plan)));
    }

    /**
     * Review and confirm: the confirmation of the approval, once the plan is
     * a Draft at the version this editor holds, nothing on the page is
     * unsaved — the votes' own editors are asked in the browser — and the
     * approval preflight finds nothing in its way. Otherwise says why not.
     */
    public function askToApprove(): void
    {
        $this->confirmingApproval = false;
        $plan = $this->approvablePlan();

        if ($plan === null) {
            return;
        }

        $problems = app(StudioPlanApprovalPreflight::class)->problems($plan);

        if ($problems !== []) {
            $this->toast('This plan isn’t ready to publish yet: '.$problems[0]->message(), 'error');

            return;
        }

        $this->confirmingApproval = true;
    }

    public function closeApproval(): void
    {
        $this->confirmingApproval = false;
    }

    /**
     * Approves the plan as it is saved, at the version this editor holds —
     * every action it will run written at once, and the executor woken — and
     * opens it again, closed, where its actions are followed as they run.
     */
    public function approve(): void
    {
        $this->confirmingApproval = false;
        $plan = $this->approvablePlan();

        if ($plan === null) {
            return;
        }

        try {
            app(ApproveStudioPlanAction::class)->handle($this->admin(), $plan, $this->lockVersion);
        } catch (CannotApproveStudioPlanException $exception) {
            $this->refuseApproval($exception);

            return;
        }

        session()->flash('rg-admin-toast', [
            'message' => app(StudioExecutionSwitch::class)->isOn()
                ? 'Publication approved. Its actions run as they become due.'
                : 'Publication approved. The executor is switched off, so nothing runs until it is switched on in the Action queue.',
            'tone' => 'success',
        ]);

        $this->redirect(self::getUrl(['record' => $plan->id]));
    }

    /**
     * The plan as Review and confirm finds it — a Draft at this editor's
     * version, with nothing on the page unsaved — or none, with the reason
     * said.
     */
    private function approvablePlan(): ?StudioPlan
    {
        if ($this->gone || $this->closed) {
            return null;
        }

        $plan = StudioPlan::query()->find($this->planId);

        if ($plan === null) {
            $this->refuse(CannotManageStudioPlanException::becausePlanIsGone(), 'approved');

            return null;
        }

        if (! $plan->isDraft()) {
            $this->refuse(CannotManageStudioPlanException::becausePlanIsNotDraft(), 'approved');

            return null;
        }

        if ($this->stale || $plan->lock_version !== $this->lockVersion) {
            $this->refuse(CannotManageStudioPlanException::becausePlanChanged(), 'approved');

            return null;
        }

        if ($this->unsavedValues() > 0) {
            $this->toast('Save your changes first: approval publishes the draft as it is saved.', 'error');

            return null;
        }

        return $plan;
    }

    /** Says why the plan was not approved, keeping the draft as it is until Reload. */
    private function refuseApproval(CannotApproveStudioPlanException $exception): void
    {
        match ($exception->rejection) {
            StudioPlanApprovalRejection::AccessDenied => $this->refuse(CannotManageStudioPlanException::becauseUserIsNotAllowed(), 'approved'),
            StudioPlanApprovalRejection::PlanGone => $this->refuse(CannotManageStudioPlanException::becausePlanIsGone(), 'approved'),
            StudioPlanApprovalRejection::NotDraft => $this->refuse(CannotManageStudioPlanException::becausePlanIsNotDraft(), 'approved'),
            StudioPlanApprovalRejection::StaleVersion => $this->refuse(CannotManageStudioPlanException::becausePlanChanged(), 'approved'),
            default => $this->toast('This plan isn’t ready to publish yet: '.($exception->problems !== [] ? $exception->problems[0]->message() : 'its author can’t publish any more.'), 'error'),
        };
    }

    /**
     * The plan moved on while its Action plan was followed — approved
     * elsewhere, running, completed: the header says so, and a plan past its
     * Draft is closed here.
     */
    #[On(StudioPlanActionPlan::MOVED_EVENT)]
    public function planMoved(mixed $planId = null): void
    {
        $plan = StudioPlan::query()->find($this->planId);

        if ($plan === null) {
            $this->gone = true;

            return;
        }

        $this->closed = ! $plan->isDraft();
    }

    public function askToDelete(): void
    {
        $this->confirmingDelete = ! $this->gone && ! $this->closed;
    }

    public function closeConfirmation(): void
    {
        $this->confirmingDelete = false;
    }

    /**
     * Deletes the Draft as it was drawn, and goes back to the list. Delete
     * draft told the page it is leaving, so nothing unsaved asks first; a
     * deletion refused takes that back.
     */
    public function delete(): void
    {
        $this->confirmingDelete = false;
        $plan = StudioPlan::query()->find($this->planId);

        if ($plan === null) {
            $this->js('$store.adminEditor.leaving = false');
            $this->refuse(CannotManageStudioPlanException::becausePlanIsGone(), 'deleted');

            return;
        }

        try {
            app(DeleteStudioPlanDraftAction::class)->handle($this->admin(), $plan, $this->lockVersion);
        } catch (CannotManageStudioPlanException $exception) {
            $this->js('$store.adminEditor.leaving = false');
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
