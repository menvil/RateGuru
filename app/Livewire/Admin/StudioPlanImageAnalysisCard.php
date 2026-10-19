<?php

namespace App\Livewire\Admin;

use App\Actions\Studio\RequestStudioImageAnalysisAction;
use App\Enums\StudioImageAnalysisError;
use App\Enums\StudioImageAnalysisStatus;
use App\Exceptions\Studio\CannotManageStudioPlanException;
use App\Exceptions\Studio\CannotRequestStudioImageAnalysisException;
use App\Models\MediaAsset;
use App\Models\StudioPlan;
use App\Models\StudioPlanImageAnalysis;
use App\Models\User;
use App\Support\StudioAi\StudioImageAnalyzer;
use App\Support\StudioAi\StudioImageDescription;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Photo analysis, beside the photo on the editor's Image step: whether the
 * saved photo is analysed, the description when it is, what the photo is,
 * and Re-run analysis or Try again — each an explicit, paid request
 * (RequestStudioImageAnalysisAction), apart from Save draft and changing
 * nothing of the draft. The first analysis of a photo is asked for by the
 * editor the moment the photo is stored; while a new one is on its way up,
 * the card says so instead of showing anything about the one before.
 *
 * A component of its own, so that while an attempt is queued or running it
 * alone is read again every few seconds — never the editor, whose draft is
 * left as typed — and stops once the attempt has finished. Livewire slows it
 * in a background tab and stops it with the step.
 *
 * It is about the photo the editor showed (`imageAssetId`), and is drawn
 * afresh when that changes. An analysis of another photo is no analysis of
 * this one; a photo changed elsewhere is said to be, and nothing is sent for
 * it. Every request checks that the viewer may still manage plans.
 */
final class StudioPlanImageAnalysisCard extends Component
{
    /** Seconds between reads while an attempt is queued or running. */
    public const POLL_SECONDS = 3;

    #[Locked]
    public int $planId = 0;

    /** The saved photo the editor showed: the one Re-run analysis sends. */
    #[Locked]
    public ?int $imageAssetId = null;

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
     * Asks for the photo to be analysed again — with the attempt on screen,
     * in place of that result or failure — or, for a photo that has none,
     * for the first time.
     */
    public function analyze(mixed $replacing = null): void
    {
        if ($this->planId === 0 || $this->imageAssetId === null) {
            return;
        }

        $plan = StudioPlan::query()->find($this->planId);

        if ($plan === null) {
            $this->toast('This draft was deleted, so its photo wasn’t sent.');

            return;
        }

        try {
            app(RequestStudioImageAnalysisAction::class)->handle(
                $this->admin(),
                $plan,
                $this->imageAssetId,
                is_string($replacing) && $replacing !== '' ? $replacing : null,
            );
        } catch (CannotManageStudioPlanException $exception) {
            $this->toast(match (true) {
                $exception->isGone() => 'This draft was deleted, so its photo wasn’t sent.',
                $exception->isNotDraft() => 'This plan is no longer a draft, so its photo wasn’t sent.',
                default => 'You can’t manage publication plans any more, so the photo wasn’t sent.',
            });
        } catch (CannotRequestStudioImageAnalysisException $exception) {
            $this->toast($exception->error === StudioImageAnalysisError::StaleImage
                ? 'The photo was changed meanwhile, so it wasn’t sent. Reload the draft to analyse the photo it has now.'
                : $exception->error->message());
        }
    }

    public function render(): View
    {
        $plan = $this->planId !== 0 ? StudioPlan::query()->with('imageAnalysis')->find($this->planId) : null;
        $now = CarbonImmutable::now();
        $configured = app(StudioImageAnalyzer::class)->isConfigured();

        $state = match (true) {
            $this->planId === 0 => 'no_image',
            $plan === null => 'gone',
            $plan->image_asset_id === null && $this->imageAssetId === null => 'no_image',
            $plan->image_asset_id !== $this->imageAssetId => 'changed',
            default => 'current',
        };

        $analysis = $state === 'current' ? $plan->imageAnalysis : null;

        if ($analysis !== null && ! $analysis->isFor($this->imageAssetId)) {
            $analysis = null;
        }

        $phase = $analysis === null ? null : self::phaseOf($analysis, $now);
        $description = $analysis?->result !== null ? StudioImageDescription::from($analysis->result) : null;
        $photo = $state === 'current' && $this->imageAssetId !== null ? MediaAsset::query()->find($this->imageAssetId) : null;

        return view('livewire.admin.studio-plan-image-analysis-card', [
            'state' => $state,
            'phase' => $phase,
            'badge' => self::badge($state === 'current' ? $phase : null, $configured),
            'attemptId' => $analysis?->attempt_id,
            'error' => $phase === 'failed' ? ($analysis->error_code ?? StudioImageAnalysisError::UnexpectedFailure)->message() : null,
            'sections' => $description !== null ? self::sections($description) : null,
            'analysedAt' => $analysis?->finished_at?->format('d M Y, H:i'),
            'model' => $analysis?->model,
            'configured' => $configured,
            'draft' => $plan?->isDraft() === true,
            'polling' => $phase === 'queued' || $phase === 'running',
            'photo' => $photo !== null ? self::photoRows($photo) : [],
        ]);
    }

    /** queued, running, stale — waiting or running for longer than a live attempt could — failed or completed. */
    private static function phaseOf(StudioPlanImageAnalysis $analysis, CarbonImmutable $now): string
    {
        if ($analysis->status->isInProgress() && $analysis->isStale($now)) {
            return 'stale';
        }

        return match ($analysis->status) {
            StudioImageAnalysisStatus::Queued => 'queued',
            StudioImageAnalysisStatus::Running => 'running',
            StudioImageAnalysisStatus::Failed => 'failed',
            StudioImageAnalysisStatus::Completed => 'completed',
        };
    }

    /**
     * The badge in the card's header: how far the photo's analysis has got.
     *
     * @return array{label: string, tone: string, dot: bool}
     */
    private static function badge(?string $phase, bool $configured): array
    {
        return match (true) {
            $phase === 'queued', $phase === 'running' => ['label' => 'Analysing', 'tone' => 'info', 'dot' => false],
            $phase === 'completed' => ['label' => 'Done', 'tone' => 'success', 'dot' => true],
            $phase === 'failed', $phase === 'stale' => ['label' => 'Failed', 'tone' => 'warning', 'dot' => false],
            ! $configured => ['label' => 'Not configured', 'tone' => 'outline', 'dot' => false],
            default => ['label' => 'Not started', 'tone' => 'outline', 'dot' => false],
        };
    }

    /**
     * What the analysed photo is, row by row: icon, label and value.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private static function photoRows(MediaAsset $photo): array
    {
        return array_values(array_filter([
            $photo->original_filename !== null ? ['file', 'File', (string) $photo->original_filename] : null,
            $photo->width !== null && $photo->height !== null ? ['ruler', 'Dimensions', $photo->width.' × '.$photo->height] : null,
            ['hard-drive', 'Size', Number::fileSize((int) $photo->byte_size, precision: 1)],
            ['file-image', 'Format', strtoupper(str_replace('image/', '', $photo->mime_type))],
        ]));
    }

    /**
     * The description as the card lists it, field by field.
     *
     * @return list<array{label: string, text?: string, items?: list<string>}>
     */
    private static function sections(StudioImageDescription $description): array
    {
        return [
            ['label' => 'Scene summary', 'text' => $description->summary],
            ['label' => 'Subjects', 'items' => $description->subjects],
            ['label' => 'Actions', 'items' => $description->actions],
            ['label' => 'Setting', 'text' => $description->setting],
            ['label' => 'Composition', 'text' => $description->composition],
            ['label' => 'Notable details', 'items' => $description->notableDetails],
            ['label' => 'Visible text', 'items' => $description->visibleText],
            ['label' => 'Uncertainties', 'items' => $description->uncertainties],
        ];
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
