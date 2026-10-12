<?php

namespace App\Filament\Pages;

use App\Filament\Support\AdminNavigationGroup;
use App\Filament\Support\TranslationAiDiagnostics;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Translation AI: technical monitoring of AI-assisted translation — whether
 * the configured provider works, what it is used for and costs, how it fails,
 * and what Generate missing is doing in the background. Read-only: nothing
 * here edits a translation or changes a setting, and nothing is sent to a
 * provider; Refresh reads the same records again.
 *
 * Drawn entirely in Admin v2. Its state is what the URL carries — the period
 * (`?period=24h|30d`, 7 days by default), a model to narrow the history to
 * (`?model=`), All or With issues (`?calls=issues`) and the page of recent
 * calls (`?page=`) — so a link opens the same view; a filter that changes
 * goes back to the first page of calls. A value the page does not offer is
 * read as its default, never as an error.
 *
 * What is shown is TranslationAiDiagnostics' — the figures from the
 * database, the configuration from configuration — and the background
 * generations a component of their own reads from the temporary store, so
 * that store being unreachable takes only that block with it.
 */
final class TranslationAiPage extends Page
{
    protected string $view = 'filament.pages.translation-ai';

    // A screen drawn in Admin v2 brings its own header band and gutter.
    protected Width|string|null $maxContentWidth = 'rg-admin-main';

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::LOCALIZATION;

    protected static ?string $navigationLabel = 'Translation AI';

    protected static ?string $title = 'Translation AI';

    protected static ?string $slug = 'translation-ai';

    protected static ?int $navigationSort = 30;

    /** The period the figures cover: 24h, 7d or 30d. */
    #[Url]
    public mixed $period = TranslationAiDiagnostics::DEFAULT_PERIOD;

    /** A model to narrow the history to, as its provider reported it; none for every model. */
    #[Url]
    public mixed $model = null;

    /** All recent calls, or only those with issues. */
    #[Url]
    public mixed $calls = 'all';

    /** The page of recent calls. */
    #[Url(as: 'page')]
    public mixed $callsPage = 1;

    public static function canAccess(): bool
    {
        return Gate::allows('manage-project-settings');
    }

    public function mount(): void
    {
        $this->normalize();
    }

    /** A new period, model or call filter starts the calls again from their first page. */
    public function updated(string $property): void
    {
        if (in_array($property, ['period', 'model', 'calls'], true)) {
            $this->callsPage = 1;
        }

        $this->normalize();
    }

    /** Reads everything again — the records, the configuration and the background generations. */
    public function refreshDiagnostics(): void
    {
        $this->normalize();
        $this->dispatch('translation-ai-refresh');
        $this->dispatch('rg-admin-toast', message: 'Translation AI refreshed', tone: 'info');
    }

    public function goToCallsPage(mixed $page): void
    {
        $this->callsPage = TranslationAiDiagnostics::page($page);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return app(TranslationAiDiagnostics::class)->build(
            (string) $this->period,
            $this->model,
            (string) $this->calls,
            TranslationAiDiagnostics::page($this->callsPage),
            CarbonImmutable::now('UTC'),
        );
    }

    /** Whatever a link or a forged update put in the URL, read as something the page offers. */
    private function normalize(): void
    {
        $this->period = TranslationAiDiagnostics::period($this->period);
        $this->calls = TranslationAiDiagnostics::callFilter($this->calls);
        $this->callsPage = TranslationAiDiagnostics::page($this->callsPage);
        $this->model = is_string($this->model) && $this->model !== '' ? mb_substr($this->model, 0, 128) : null;
    }
}
