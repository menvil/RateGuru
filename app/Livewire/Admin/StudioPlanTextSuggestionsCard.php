<?php

namespace App\Livewire\Admin;

use App\Actions\Studio\RequestStudioPostTextGenerationAction;
use App\Enums\StudioPostTextGenerationError;
use App\Enums\StudioPostTextGenerationStatus;
use App\Enums\StudioPostTextTone;
use App\Exceptions\Studio\CannotManageStudioPlanException;
use App\Exceptions\Studio\CannotRequestStudioPostTextGenerationException;
use App\Models\StudioPlan;
use App\Models\StudioPlanTextGeneration;
use App\Models\User;
use App\Support\Media\PostImagePresenter;
use App\Support\StudioAi\StudioPostTextContext;
use App\Support\StudioAi\StudioPostTextGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\Component;

/**
 * AI text suggestions, on the editor's Post step: the photo and what its
 * analysis says, whose voice the texts are written in — the saved author's
 * — the tone of the next batch, Generate suggestions — then Generate 10
 * more, or Try again after a failure — each an explicit, paid request
 * (RequestStudioPostTextGenerationAction), and the newest batch written from
 * the photo, analysis and author's voice the plan has now, its titles and
 * its descriptions side by side. A new batch takes the place of the one
 * before; the earlier ones stay stored, but are shown no more.
 *
 * The texts are written in the voice of the author the plan is saved with.
 * Generate, clicked with another author chosen in the editor and not saved
 * yet, has the editor save the draft first (`save-studio-plan`, `then`
 * `suggest`), and the batch is asked for once the editor says it is saved
 * (GENERATE_EVENT); a save of the plan from anywhere on the page draws the
 * card again, so a batch for another author is shown no more.
 *
 * Choosing a title or a description hands it to the editor's draft
 * (`use-studio-suggestion`), as typing it would: nothing is saved, nothing
 * else changes, and the final texts stay the editor's to edit and to save
 * with Save draft. A batch written for another photo, analysis or author's
 * voice is shown no more and used never.
 *
 * A component of its own, so that while a batch — or the photo's analysis
 * it waits for — is on its way it alone is read again every few seconds,
 * never the editor, whose draft is left as typed. It is drawn afresh for
 * every photo, and every request checks that the viewer may still manage
 * plans.
 */
final class StudioPlanTextSuggestionsCard extends Component
{
    /** Seconds between reads while a batch, or the analysis, is on its way. */
    public const POLL_SECONDS = 3;

    /**
     * Said by the plan's editor once it saved the draft for Generate, clicked
     * with another author chosen: the batch is asked for in the voice of the
     * author saved.
     */
    public const GENERATE_EVENT = 'studio-text-suggestions-generate';

    #[Locked]
    public int $planId = 0;

    /** The saved photo the editor showed: the one batches are asked for. */
    #[Locked]
    public ?int $imageAssetId = null;

    /** The tone the next batch is asked in. */
    public string $tone = StudioPostTextTone::DEFAULT->value;

    /**
     * The author the plan is saved with, as the card last drew it — what the
     * editor's own choice is held up to in the browser, read again with every
     * drawing so it follows a save at once.
     */
    #[Locked]
    public string $savedAuthorId = '';

    public function boot(): void
    {
        abort_unless(Gate::allows('manage-studio-plans'), 403);
    }

    /** @param  int  $planId  0 on a new publication, before a photo has made its Draft */
    public function mount(int $planId = 0, ?int $imageAssetId = null): void
    {
        $this->planId = $planId;
        $this->imageAssetId = $imageAssetId;
    }

    /**
     * Asks for a batch in the chosen tone: the first, another, or one in
     * place of a failure — in the voice of the author the editor shows, which
     * must be the one saved.
     */
    public function generate(mixed $authorUserId = null): void
    {
        if ($this->planId === 0 || $this->imageAssetId === null) {
            return;
        }

        $authorId = is_numeric($authorUserId) && (int) $authorUserId > 0 ? (int) $authorUserId : null;

        $plan = StudioPlan::query()->find($this->planId);

        if ($plan === null) {
            $this->toast('This draft was deleted, so nothing was sent.');

            return;
        }

        try {
            app(RequestStudioPostTextGenerationAction::class)->handle($this->admin(), $plan, $this->imageAssetId, $authorId, $this->chosenTone());
        } catch (CannotManageStudioPlanException $exception) {
            $this->toast(match (true) {
                $exception->isGone() => 'This draft was deleted, so nothing was sent.',
                $exception->isNotDraft() => 'This plan is no longer a draft, so nothing was sent.',
                default => 'You can’t manage publication plans any more, so nothing was sent.',
            });
        } catch (CannotRequestStudioPostTextGenerationException $exception) {
            $this->toast($exception->error === StudioPostTextGenerationError::StaleAnalysis
                ? 'The photo was changed meanwhile, so nothing was sent. Reload the draft to write texts for the photo it has now.'
                : $exception->error->message());
        }
    }

    /** The editor saved the draft for Generate: the batch is asked for now, in the voice of the author it saved. */
    #[On(self::GENERATE_EVENT)]
    public function generateOnceSaved(mixed $planId = null, mixed $authorUserId = null): void
    {
        if ((int) $planId === $this->planId) {
            $this->generate($authorUserId);
        }
    }

    /**
     * The plan was saved or drawn again on the page: drawn again here too,
     * so a batch written for an author it no longer has is shown no more —
     * unless it was the discussion or the votes, which change nothing here.
     */
    #[On(StudioPlanDiscussionEditor::VERSION_EVENT)]
    #[On(StudioPlanDiscussionEditor::RELOAD_EVENT)]
    #[Renderless]
    public function planMoved(mixed $source = null): void
    {
        if (! in_array($source, ['discussion', StudioPlanPostVotesEditor::SOURCE, StudioPlanDiscussionVotesEditor::SOURCE], true)) {
            $this->forceRender();
        }
    }

    /**
     * A field set on a request that also carries another editor's write is
     * drawn too: the listener above is renderless (#[Renderless]), which
     * Livewire honours only when a request carries nothing else.
     */
    public function updated(): void
    {
        $this->forceRender();
    }

    public function useTitle(mixed $generationId, mixed $index): void
    {
        $this->use('title', $generationId, $index);
    }

    public function useDescription(mixed $generationId, mixed $index): void
    {
        $this->use('description', $generationId, $index);
    }

    public function render(): View
    {
        $plan = $this->planId !== 0 ? StudioPlan::query()->with(['imageAnalysis', 'imageAsset.variants'])->find($this->planId) : null;
        $generator = app(StudioPostTextGenerator::class);
        $now = CarbonImmutable::now();
        $context = $plan !== null ? StudioPostTextContext::ofPlan($plan, $plan->imageAnalysis) : null;
        $this->savedAuthorId = $plan?->author_user_id !== null ? (string) $plan->author_user_id : '';

        $state = match (true) {
            $this->planId === 0 => 'no_image',
            $plan === null => 'gone',
            $plan->image_asset_id === null && $this->imageAssetId === null => 'no_image',
            $plan->image_asset_id !== $this->imageAssetId => 'changed',
            $context === null || ! $context->complete => 'analysis_pending',
            default => 'ready',
        };

        $generations = $plan !== null && $context !== null
            ? StudioPlanTextGeneration::query()->where('studio_plan_id', $plan->id)->orderByDesc('id')->get()
                ->filter(fn (StudioPlanTextGeneration $generation): bool => $generation->isFor($context->imageAssetId, $context->sourceHash()))
                ->values()
            : collect();

        $batches = $generations->where('status', StudioPostTextGenerationStatus::Completed)->values();
        $batch = $batches->first();
        $latest = $generations->first();
        $phase = match (true) {
            $latest === null, $latest->status === StudioPostTextGenerationStatus::Completed => null,
            $latest->status->isInProgress() && $latest->isStale($now) => 'stale',
            $latest->status === StudioPostTextGenerationStatus::Queued => 'queued',
            $latest->status === StudioPostTextGenerationStatus::Running => 'running',
            default => 'failed',
        };
        // The photo's analysis on its way: read again until it is there.
        $analysisOnItsWay = $plan?->imageAnalysis !== null
            && $plan->imageAnalysis->isFor($plan->image_asset_id)
            && $plan->imageAnalysis->status->isInProgress();

        return view('livewire.admin.studio-plan-text-suggestions-card', [
            'state' => $state,
            'phase' => $phase,
            'error' => $phase === 'failed' ? ($latest->error_code ?? StudioPostTextGenerationError::UnexpectedFailure)->message() : null,
            'configured' => $generator->isConfigured(),
            'count' => $generator->batchSize(),
            'draft' => $plan?->isDraft() === true,
            'photo' => $plan?->imageAsset !== null && $plan->image_asset_id === $this->imageAssetId ? app(PostImagePresenter::class)->assetPreviewUrl($plan->imageAsset) : null,
            'summary' => $state === 'ready' ? $context->description->summary : null,
            'voice' => $state === 'ready' && $context->author !== null ? ['name' => $context->author->name, 'about' => $context->author->shortDescription] : null,
            'tones' => array_map(fn (StudioPostTextTone $tone): array => ['value' => $tone->value, 'label' => $tone->label(), 'hint' => $tone->hint()], StudioPostTextTone::cases()),
            'chosenTone' => $this->chosenTone(),
            'batch' => $batch === null ? null : [
                'id' => (int) $batch->id,
                'set' => $batches->count(),
                'tone' => $batch->tone,
                'titles' => array_values(array_filter((array) $batch->titles, is_string(...))),
                'descriptions' => array_values(array_filter((array) $batch->descriptions, is_string(...))),
            ],
            'polling' => $phase === 'queued' || $phase === 'running' || ($state === 'analysis_pending' && $analysisOnItsWay),
        ]);
    }

    /**
     * Hands one text of a batch to the editor's draft — only from a batch
     * written for the photo, analysis and author's voice the plan has now.
     */
    private function use(string $field, mixed $generationId, mixed $index): void
    {
        $plan = $this->planId !== 0 ? StudioPlan::query()->with('imageAnalysis')->find($this->planId) : null;
        $context = $plan !== null ? StudioPostTextContext::ofPlan($plan, $plan->imageAnalysis) : null;
        $generation = $plan !== null && is_numeric($generationId)
            ? StudioPlanTextGeneration::query()->where('studio_plan_id', $plan->id)->whereKey((int) $generationId)->first()
            : null;

        if ($plan === null || $context === null || $generation === null || $plan->image_asset_id !== $this->imageAssetId
            || $generation->status !== StudioPostTextGenerationStatus::Completed
            || ! $generation->isFor($context->imageAssetId, $context->sourceHash())) {
            $this->toast('These suggestions were written for an earlier photo, analysis or author, so nothing was used.');

            return;
        }

        $texts = $field === 'title' ? $generation->titles : $generation->descriptions;
        $text = is_numeric($index) && is_array($texts) ? ($texts[(int) $index] ?? null) : null;

        if (is_string($text)) {
            $this->dispatch('use-studio-suggestion', field: $field, text: $text);
        }
    }

    private function chosenTone(): StudioPostTextTone
    {
        return StudioPostTextTone::tryFrom($this->tone) ?? StudioPostTextTone::DEFAULT;
    }

    private function admin(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function toast(string $message): void
    {
        $this->dispatch('rg-admin-toast', message: $message, tone: 'error');
    }
}
