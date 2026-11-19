<?php

namespace App\Filament\Pages;

use App\Actions\Games\HideGameTrafficPeriodAction;
use App\Exceptions\Games\CannotManageGameException;
use App\Filament\Pages\Experiments\ExperimentsView;
use App\Filament\Support\AdminNavigationGroup;
use App\Filament\Support\ListPageNumber;
use App\Filament\Support\ShowsItemsInPlace;
use App\Models\GameTrafficPeriod;
use App\Models\User;
use App\Queries\Games\ComparisonPeriodsQuery;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Growth → Experiments: every traffic period of the games not archived —
 * each Apply in a game's Traffic tab starts one — and, for a period, its
 * results: its versions compared on the same metrics against the version
 * with the largest share. Nothing is set up here; shares change in a game's
 * Traffic tab. An ended period can be deleted from the list, which only
 * hides it (HideGameTrafficPeriodAction), with Undo.
 *
 * Everything on screen is in the address — the search, Show, Status, the
 * page, and the period whose results are open — and anything it can't read
 * is the default. A period opens by its own address, so the top bar's
 * breadcrumb leads back to the list. What is drawn comes from
 * ExperimentsView; the page, and every request it answers, is an active
 * administrator's who may manage games.
 */
final class ExperimentsPage extends Page implements ShowsItemsInPlace
{
    protected string $view = 'filament.pages.experiments';

    protected Width|string|null $maxContentWidth = 'rg-admin-main';

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::GROWTH;

    protected static ?string $navigationLabel = 'Experiments';

    protected static ?string $title = 'Experiments';

    protected static ?string $slug = 'experiments';

    protected static ?int $navigationSort = 2;

    #[Url(except: '')]
    public mixed $q = '';

    /** Every period, or only comparisons: one of ComparisonPeriodsQuery::SHOWS. */
    #[Url(except: 'all')]
    public mixed $show = 'all';

    /** Every period, the current ones or the ended ones: one of ComparisonPeriodsQuery::STATUSES. */
    #[Url(except: 'all')]
    public mixed $status = 'all';

    #[Url(as: 'page', except: 1)]
    public mixed $pageNumber = 1;

    /** The period whose results are open, by id; the list without one. */
    #[Url(except: 0)]
    public mixed $period = 0;

    public bool $ready = false;

    public static function canAccess(): bool
    {
        return Gate::allows('manage-games');
    }

    /** The comparisons running now: open periods sharing their visitors between two versions or more. */
    public static function getNavigationBadge(): ?string
    {
        $running = app(ComparisonPeriodsQuery::class)->openComparisons();

        return $running > 0 ? (string) $running : null;
    }

    public static function getNavigationBadgeTooltip(): string
    {
        return 'Comparisons running now';
    }

    /** A period's results open inside the page, by their own address. */
    public static function showsItem(Request $request): bool
    {
        return self::periodId($request->query('period')) > 0;
    }

    /**
     * The top bar of a period's results (a panel render hook): rendered with
     * the page's own request, so after Delete period or Show in Experiments
     * the page reloads its address rather than redrawing in place.
     */
    public static function topbar(Request $request): string
    {
        $period = self::periodId($request->query('period'));
        $actions = $period > 0 && self::canAccess() ? app(ExperimentsView::class)->topbar($period) : null;

        return $actions === null ? '' : view('filament.pages.experiments.topbar', $actions)->render();
    }

    /** The period's figures are read once the page is in a browser (wire:init), never by asking for it. */
    public function load(): void
    {
        $this->ready = true;
    }

    public function mount(): void
    {
        $this->normalize();

        // Deleting a period comes back to the list, which says so, with Undo.
        $toast = session('rg-admin-toast');

        if (is_array($toast) && is_string($toast['message'] ?? null)) {
            $this->dispatch('rg-admin-toast', message: $toast['message'], tone: 'success', action: is_array($toast['action'] ?? null) ? $toast['action'] : null);
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['q', 'show', 'status'], true)) {
            $this->pageNumber = 1;
        }

        $this->normalize();
    }

    public function goToPage(mixed $page): void
    {
        $this->pageNumber = ListPageNumber::from($page);
    }

    public function clearFilters(): void
    {
        $this->q = '';
        $this->show = 'all';
        $this->status = 'all';
        $this->pageNumber = 1;
    }

    /**
     * Delete period: the open period, ended, leaves the list and its
     * switcher — its numbers stay — and the page goes back to the list, which
     * offers Undo.
     */
    #[On('delete-experiment-period')]
    public function deletePeriod(): void
    {
        $period = GameTrafficPeriod::query()->find(self::periodId($this->period));

        if ($period === null) {
            return;
        }

        try {
            app(HideGameTrafficPeriodAction::class)->hide($this->admin(), $period);
        } catch (CannotManageGameException $exception) {
            $this->dispatch('rg-admin-toast', message: $exception->getMessage(), tone: 'error');

            return;
        }

        session()->flash('rg-admin-toast', [
            'message' => 'Period deleted. Its numbers stay in the database.',
            'action' => ['label' => 'Undo', 'event' => 'show-experiment-period', 'params' => ['id' => (int) $period->id]],
        ]);

        $this->redirect(self::getUrl());
    }

    /** Undo after Delete period, and Show in Experiments on a hidden period: listed again. */
    #[On('show-experiment-period')]
    public function showPeriod(mixed $id = null): void
    {
        $period = GameTrafficPeriod::query()->find(self::periodId($id ?? $this->period));

        if ($period === null) {
            return;
        }

        try {
            app(HideGameTrafficPeriodAction::class)->show($this->admin(), $period);
        } catch (CannotManageGameException $exception) {
            $this->dispatch('rg-admin-toast', message: $exception->getMessage(), tone: 'error');

            return;
        }

        // On the period's own page the top bar offers Delete period again, so the page reloads.
        if (self::periodId($this->period) === (int) $period->id) {
            session()->flash('rg-admin-toast', ['message' => 'Period back in Experiments.']);
            $this->redirect(self::getUrl(['period' => $period->id]));

            return;
        }

        $this->dispatch('rg-admin-toast', message: 'Period back in Experiments.', tone: 'success');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $view = app(ExperimentsView::class);
        $period = self::periodId($this->period);

        if (! $this->ready) {
            return ['ready' => false, 'showsPeriod' => $period > 0];
        }

        if ($period > 0) {
            return ['showsPeriod' => true, ...$view->period($period)];
        }

        $list = $view->list((string) $this->q, (string) $this->show, (string) $this->status, ListPageNumber::from($this->pageNumber));
        $this->pageNumber = $list['pageNumber'];

        return [
            'showsPeriod' => false,
            'shows' => ['all' => 'All periods', 'comparisons' => 'Comparisons'],
            'statuses' => ['all' => 'All', 'current' => 'Current', 'ended' => 'Ended'],
            ...$list,
        ];
    }

    /** A period's id from the address or a request: a whole number, or none (0) for anything else. */
    private static function periodId(mixed $value): int
    {
        $id = match (true) {
            is_int($value) => $value,
            is_string($value) && ctype_digit($value) && strlen($value) <= 18 => (int) $value,
            default => 0,
        };

        return max(0, $id);
    }

    private function normalize(): void
    {
        $this->q = is_string($this->q) ? mb_substr($this->q, 0, 100) : '';
        $this->show = is_string($this->show) && in_array($this->show, ComparisonPeriodsQuery::SHOWS, true) ? $this->show : 'all';
        $this->status = is_string($this->status) && in_array($this->status, ComparisonPeriodsQuery::STATUSES, true) ? $this->status : 'all';
        $this->pageNumber = ListPageNumber::from($this->pageNumber);
        $this->period = self::periodId($this->period);
    }

    private function admin(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
