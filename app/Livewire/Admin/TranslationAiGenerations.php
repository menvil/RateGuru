<?php

namespace App\Livewire\Admin;

use App\Exceptions\Translations\CannotGenerateTranslationsException;
use App\Filament\Pages\TranslationCenterPage;
use App\Support\Locale\LocaleManager;
use App\Support\Translations\Generation\ProjectTranslationGenerationMonitor;
use App\Support\Translations\Generation\ProjectTranslationGenerationProgress;
use App\Support\Translations\Generation\ProjectTranslationGenerationStatus;
use App\Support\Translations\TranslatableField;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Translation AI's Background generations: what Generate missing is doing
 * now, read from the temporary store it keeps its batches in.
 *
 * A component of its own, so the store being unreachable costs this block
 * only — it says Temporarily unavailable and the rest of the page carries on
 * — and so that, while a generation runs, it alone is read again every few
 * seconds; the page's figures are never polled. It stops once nothing runs,
 * and Livewire slows it in a background tab.
 *
 * Only what monitoring may show reaches the browser: a language, a status,
 * counts and times (ProjectTranslationGenerationProgress) — never a unit, a
 * text, a suggestion or a snapshot. Every request checks that the viewer may
 * still manage project settings.
 */
final class TranslationAiGenerations extends Component
{
    /** Seconds between reads while a generation is queued or running. */
    public const POLL_SECONDS = 5;

    public function boot(): void
    {
        abort_unless(Gate::allows('manage-project-settings'), 403);
    }

    /** The page's Refresh reads this block again too. */
    #[On('translation-ai-refresh')]
    public function reread(): void {}

    public function render(): View
    {
        $now = CarbonImmutable::now('UTC');
        $locales = app(LocaleManager::class);
        $codes = array_values(array_filter(array_keys($locales->supported()), fn (string $code): bool => $code !== TranslatableField::REFERENCE_LOCALE));
        $viewer = auth()->id();

        try {
            $generations = app(ProjectTranslationGenerationMonitor::class)->current($codes, is_int($viewer) ? $viewer : null, $now);
        } catch (CannotGenerateTranslationsException) {
            return view('livewire.admin.translation-ai-generations', ['available' => false, 'rows' => [], 'polling' => false, 'checkedAt' => $now->format('H:i:s').' UTC']);
        }

        return view('livewire.admin.translation-ai-generations', [
            'available' => true,
            'rows' => array_map(fn (ProjectTranslationGenerationProgress $generation): array => $this->row($generation, $locales, $now), $generations),
            'polling' => array_filter($generations, fn (ProjectTranslationGenerationProgress $generation): bool => $generation->isActive()) !== [],
            'checkedAt' => $now->format('H:i:s').' UTC',
        ]);
    }

    /** @return array<string, mixed> */
    private function row(ProjectTranslationGenerationProgress $generation, LocaleManager $locales, CarbonImmutable $now): array
    {
        $percentage = $generation->total > 0 ? (int) floor($generation->processed / $generation->total * 100) : 100;
        $expires = $generation->expiresAt;

        return [
            'flag' => $locales->flag($generation->locale),
            'language' => $locales->label($generation->locale),
            'native' => $locales->nativeLabel($generation->locale),
            'locale' => $generation->locale,
            'status' => match ($generation->status) {
                ProjectTranslationGenerationStatus::Queued => ['label' => 'Queued', 'tone' => 'info', 'dot' => true],
                ProjectTranslationGenerationStatus::Running => ['label' => 'Running', 'tone' => 'info', 'dot' => true],
                ProjectTranslationGenerationStatus::Completed => ['label' => 'Completed', 'tone' => 'success', 'dot' => false],
            },
            'active' => $generation->isActive(),
            'percentage' => $percentage,
            'total' => number_format($generation->total),
            'processed' => number_format($generation->processed),
            'counts' => [
                ['label' => 'Ready', 'value' => number_format($generation->ready)],
                ['label' => 'Failed', 'value' => number_format($generation->failed)],
                ['label' => 'Skipped', 'value' => number_format($generation->skipped)],
                ['label' => 'Saved', 'value' => number_format($generation->saved)],
                ['label' => 'Discarded', 'value' => number_format($generation->discarded)],
            ],
            'owner' => $generation->startedByViewer ? 'Started by you' : 'Started by another administrator',
            'started' => $generation->createdAt->format('j M H:i').' UTC',
            'expires' => ($expires->isSameDay($now) ? 'today' : $expires->format('j M')).' '.$expires->format('H:i').' UTC',
            'review' => $generation->startedByViewer && $generation->ready > 0 ? TranslationCenterPage::getUrl(['locale' => $generation->locale]) : null,
        ];
    }
}
