<?php

namespace App\Filament\Resources\StudioPlans\Pages;

use App\Actions\Studio\DeleteStudioPlanDraftAction;
use App\Enums\StudioPlanStatus;
use App\Exceptions\Studio\CannotManageStudioPlanException;
use App\Filament\Resources\StudioPlans\StudioPlanFormat;
use App\Filament\Resources\StudioPlans\StudioPlanResource;
use App\Filament\Support\ModerationListFormat;
use App\Models\StudioPlan;
use App\Models\User;
use App\Queries\Studio\StudioPlansQuery;
use App\Support\Media\PostImagePresenter;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/**
 * Content Studio's Publications & plans in Admin v2: every plan, the most
 * recently saved first, searched and narrowed by state and photo on the
 * server, 25 at a time, each with its editor and, while it is a Draft, Delete
 * draft. Nothing on this page publishes or schedules anything: a plan is
 * approved from its own editor, and runs in the Action queue.
 */
final class ListStudioPlans extends Page
{
    protected static string $resource = StudioPlanResource::class;

    protected string $view = 'filament.pages.studio-plans';

    // A screen drawn in Admin v2 brings its own header band and gutter.
    protected Width|string|null $maxContentWidth = 'rg-admin-main';

    /** The states the list can be narrowed to: every state a plan passes through. */
    public const STATES = [StudioPlanStatus::Draft, StudioPlanStatus::Approved, StudioPlanStatus::Running, StudioPlanStatus::Completed, StudioPlanStatus::Cancelled];

    /** The search over the title, the id and the author. */
    #[Url(except: '')]
    public mixed $q = '';

    /** A state, or every one. Typed loosely: whatever a link puts here reads as a filter. */
    #[Url(except: '')]
    public mixed $state = '';

    /** With a photo, without one, or either. */
    #[Url(except: '')]
    public mixed $image = '';

    #[Url(as: 'page', except: 1)]
    public mixed $pageNumber = 1;

    /** The Draft the confirmation on screen would delete. */
    #[Locked]
    public ?int $confirmingId = null;

    /** Its version as the list showed it: a draft changed since is not deleted unseen. */
    #[Locked]
    public ?int $confirmingVersion = null;

    public static function canAccess(array $parameters = []): bool
    {
        return StudioPlanResource::canAccess();
    }

    public function getTitle(): string
    {
        return 'Publications & plans';
    }

    public function mount(): void
    {
        $this->normalize();

        // Deleted from its editor a moment ago.
        $toast = session('rg-admin-toast');

        if (is_array($toast) && is_string($toast['message'] ?? null)) {
            $this->dispatch('rg-admin-toast', message: $toast['message'], tone: is_string($toast['tone'] ?? null) ? $toast['tone'] : 'success');
        }
    }

    /** A new search or filter starts again from the first page. */
    public function updated(string $property): void
    {
        if (in_array($property, ['q', 'state', 'image'], true)) {
            $this->pageNumber = 1;
        }

        $this->normalize();
    }

    public function goToPage(mixed $page): void
    {
        $this->pageNumber = max(1, (int) $page);
    }

    public function clearFilters(): void
    {
        $this->q = '';
        $this->state = '';
        $this->image = '';
        $this->pageNumber = 1;
    }

    /**
     * Asks before a Draft is deleted — the Draft as its row showed it
     * (`$version`), never as it is by the time Delete is pressed: a draft
     * somebody saved since the list was drawn is not offered for deletion
     * unseen, and the confirmation deletes only the version it asked about.
     */
    public function askToDelete(mixed $id, mixed $version): void
    {
        $this->closeConfirmation();
        $plan = is_numeric($id) ? StudioPlan::query()->find((int) $id) : null;

        if ($plan === null || ! $plan->isDraft()) {
            $this->dispatch('rg-admin-toast', message: $plan === null ? 'This draft was already deleted.' : 'Only a draft can be deleted.', tone: 'info');

            return;
        }

        if (! is_numeric($version) || (int) $version !== $plan->lock_version) {
            $this->dispatch('rg-admin-toast', message: 'Someone changed this draft since the list was drawn. Refresh the list and check it before deleting.', tone: 'error');

            return;
        }

        $this->confirmingId = $plan->id;
        $this->confirmingVersion = (int) $version;
    }

    public function closeConfirmation(): void
    {
        $this->confirmingId = null;
        $this->confirmingVersion = null;
    }

    /** Deletes the Draft the confirmation is about, as it was when it was asked. */
    public function delete(): void
    {
        if ($this->confirmingId === null || $this->confirmingVersion === null) {
            return;
        }

        [$id, $version] = [$this->confirmingId, $this->confirmingVersion];
        $this->closeConfirmation();
        $plan = StudioPlan::query()->find($id);

        if ($plan === null) {
            $this->dispatch('rg-admin-toast', message: 'This draft was already deleted.', tone: 'info');

            return;
        }

        try {
            app(DeleteStudioPlanDraftAction::class)->handle($this->admin(), $plan, $version);
        } catch (CannotManageStudioPlanException $exception) {
            $this->dispatch('rg-admin-toast', ...match (true) {
                $exception->isGone() => ['message' => 'This draft was already deleted.', 'tone' => 'info'],
                $exception->isStale() => ['message' => 'Someone changed this draft since the list was drawn, so it wasn’t deleted. Check it and try again.', 'tone' => 'error'],
                $exception->isNotDraft() => ['message' => 'This plan is no longer a draft, so it wasn’t deleted.', 'tone' => 'error'],
                default => ['message' => 'You can’t manage publication plans any more, so nothing was deleted.', 'tone' => 'error'],
            });

            return;
        }

        $this->dispatch('rg-admin-toast', message: StudioPlanFormat::title($plan).' deleted', tone: 'success');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $query = app(StudioPlansQuery::class);
        $stats = $query->stats();
        $state = StudioPlanStatus::tryFrom((string) $this->state);
        $withImage = match ((string) $this->image) {
            'with' => true,
            'without' => false,
            default => null,
        };
        $paginator = $query->paginate((string) $this->q, $state, $withImage, (int) $this->pageNumber);

        // A page past the end — an old link, or plans gone since the list was
        // drawn — shows the last one there is instead, and says so in the address.
        if ($paginator->currentPage() > $paginator->lastPage()) {
            $this->pageNumber = $paginator->lastPage();
            $paginator = $query->paginate((string) $this->q, $state, $withImage, $this->pageNumber);
        }

        $rows = array_map(fn (StudioPlan $plan): array => $this->row($plan), $paginator->items());

        return [
            'stats' => array_map(fn (int $count): string => number_format($count), $stats),
            'states' => [
                ['value' => '', 'label' => 'All states', 'trigger' => 'All'],
                ...array_map(fn (StudioPlanStatus $status): array => ['value' => $status->value, 'label' => $status->label()], self::STATES),
            ],
            'images' => [
                ['value' => '', 'label' => 'With or without', 'trigger' => 'All'],
                ['value' => 'with', 'label' => 'With image'],
                ['value' => 'without', 'label' => 'Without image'],
            ],
            'stateName' => $state?->label(),
            'imageName' => $withImage === null ? null : ($withImage ? 'With image' : 'Without image'),
            'paginator' => $paginator,
            'rows' => $rows,
            'filtered' => trim((string) $this->q) !== '' || $state !== null || $withImage !== null,
            'createUrl' => CreateStudioPlan::getUrl(),
            'empty' => (int) $stats['total'] === 0,
            'confirmation' => $this->confirmation(),
        ];
    }

    /**
     * One plan as its row shows it.
     *
     * @return array<string, mixed>
     */
    private function row(StudioPlan $plan): array
    {
        return [
            'id' => (int) $plan->id,
            'title' => StudioPlanFormat::title($plan),
            'untitled' => trim((string) $plan->title) === '',
            'editUrl' => EditStudioPlan::getUrl(['record' => $plan]),
            'author' => StudioPlanFormat::author($plan->author),
            'hasImage' => $plan->imageAsset !== null,
            'thumbnail' => app(PostImagePresenter::class)->assetThumbnailUrl($plan->imageAsset),
            'when' => StudioPlanFormat::publication($plan->publication_mode, $plan->publish_at),
            'state' => StudioPlanFormat::state($plan->status),
            'draft' => $plan->isDraft(),
            'version' => $plan->lock_version,
            'updated' => $plan->updated_at !== null ? ModerationListFormat::when($plan->updated_at) : '—',
        ];
    }

    /**
     * The Draft the confirmation on screen is about.
     *
     * @return array{id: int, title: string}|null
     */
    private function confirmation(): ?array
    {
        $plan = $this->confirmingId !== null ? StudioPlan::query()->find($this->confirmingId) : null;

        return $plan !== null ? ['id' => (int) $plan->id, 'title' => StudioPlanFormat::title($plan)] : null;
    }

    private function admin(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /** Whatever a link or a forged update put here, read as something the page offers. */
    private function normalize(): void
    {
        $state = is_string($this->state) ? StudioPlanStatus::tryFrom($this->state) : null;

        $this->state = $state !== null && in_array($state, self::STATES, true) ? $state->value : '';
        $this->image = is_string($this->image) && in_array($this->image, ['with', 'without'], true) ? $this->image : '';
        $this->q = is_string($this->q) ? mb_substr($this->q, 0, 100) : '';
        $this->pageNumber = max(1, is_numeric($this->pageNumber) ? (int) $this->pageNumber : 1);
    }
}
