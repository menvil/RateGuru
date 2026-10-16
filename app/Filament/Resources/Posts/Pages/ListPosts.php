<?php

namespace App\Filament\Resources\Posts\Pages;

use App\Actions\Moderation\ApprovePostAction;
use App\Actions\Moderation\FinalizePostRemovalAction;
use App\Actions\Moderation\HidePostAction;
use App\Actions\Moderation\MarkUserTrustedAction;
use App\Actions\Moderation\RejectPostAction;
use App\Actions\Moderation\RestorePostAction;
use App\Enums\PostStatus;
use App\Enums\ReportReason;
use App\Enums\UserRole;
use App\Exceptions\Moderation\CannotFinalizeRemovalException;
use App\Exceptions\Moderation\CannotModeratePostException;
use App\Filament\Resources\Posts\PostResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\ModerationListFormat;
use App\Models\Category;
use App\Models\Post;
use App\Models\User;
use App\Queries\Posts\ModerationPostsQuery;
use App\Support\Media\PostImagePresenter;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;

/**
 * Posts in Admin v2: the moderation queue and every other post, a tab at a
 * time, searched and narrowed on the server and read 25 at a time.
 *
 * Pending posts are approved at once or rejected with an optional reason;
 * published ones are hidden with a required reason, hidden ones restored at
 * once — both with Undo. Rows can be selected to approve or hide together.
 * An administrator finalizes the removal of a hidden post, which nothing
 * takes back. Every change goes through the moderation action that checks
 * the moderator, locks the post and writes the moderation log.
 */
final class ListPosts extends Page
{
    /** The tabs, in order, and their labels. */
    public const TABS = [
        'all' => 'All',
        'pending' => 'Pending',
        'published' => 'Published',
        'hidden' => 'Hidden',
        'reported' => 'Reported',
        'deleted' => 'Deleted by author',
    ];

    /** The orders a list is read in. Pending waits oldest first; every other tab is newest first. */
    public const SORTS = ['oldest' => 'Oldest first', 'newest' => 'Newest first', 'reports' => 'Most reported'];

    /** How long a moderation reason may be. */
    public const REASON_LIMIT = 500;

    /** A post waiting this many minutes shows its wait in the due colour. */
    private const DUE_AFTER_MINUTES = 120;

    protected static string $resource = PostResource::class;

    protected string $view = 'filament.pages.posts';

    // A screen drawn in Admin v2 brings its own header band and gutter.
    protected Width|string|null $maxContentWidth = 'rg-admin-main';

    /** The open tab. Typed loosely: whatever a link puts here reads as a tab. */
    #[Url(except: 'pending')]
    public mixed $status = 'pending';

    /** The search over the title, the author and the post's id. */
    #[Url(except: '')]
    public mixed $q = '';

    /** A category's slug, or none for every category. */
    #[Url(except: '')]
    public mixed $category = '';

    /** The order, or none for the tab's own. */
    #[Url(except: '')]
    public mixed $sort = '';

    #[Url(as: 'page', except: 1)]
    public mixed $pageNumber = 1;

    /** @var list<string> the selected posts' ids, as the checkboxes hold them */
    public array $selected = [];

    /**
     * The confirmation on screen: what it confirms and for which posts.
     *
     * @var array{action: 'reject'|'hide'|'finalize', ids: list<int>}|null
     */
    #[Locked]
    public ?array $confirming = null;

    public string $reason = '';

    /** The acknowledgement an irreversible confirmation asks for. */
    public bool $acknowledged = false;

    /** The post whose original image is open. */
    #[Locked]
    public ?int $viewing = null;

    public function getTitle(): string
    {
        return 'Posts';
    }

    public function mount(): void
    {
        $this->normalize();
    }

    /** A new tab, search, category or order starts again from the first page, with nothing selected. */
    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'q', 'category', 'sort'], true)) {
            $this->pageNumber = 1;
            $this->selected = [];
        }

        $this->normalize();
    }

    public function goToPage(mixed $page): void
    {
        $this->pageNumber = max(1, (int) $page);
        $this->selected = [];
    }

    public function clearFilters(): void
    {
        $this->q = '';
        $this->category = '';
        $this->pageNumber = 1;
        $this->selected = [];
    }

    /** Reads the list again, for posts submitted or moderated since it was drawn. Refresh in the top bar calls it. */
    public function refresh(): void
    {
        $this->normalize();
        $this->dispatch('rg-admin-posts-refreshed', at: now()->format('H:i'));
    }

    /** Selects every row on the page, or clears a selection that has any. */
    public function togglePage(): void
    {
        $this->selected = $this->selected !== []
            ? []
            : array_map(strval(...), app(ModerationPostsQuery::class)->pageIds((string) $this->status, (string) $this->q, $this->categoryId(), $this->order(), (int) $this->pageNumber));
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    public function approve(int $id): void
    {
        $post = $this->find($id);

        if ($post === null || ! $this->attempt(fn () => app(ApprovePostAction::class)->handle($this->moderator(), $post))) {
            return;
        }

        $this->toast('“'.self::shortTitle($post).'” approved and published');
    }

    public function approveSelected(): void
    {
        $posts = app(ModerationPostsQuery::class)->among($this->selectedIds())->where('posts.status', PostStatus::Pending)->get();
        $approved = 0;

        foreach ($posts as $post) {
            $approved += $this->attempt(fn () => app(ApprovePostAction::class)->handle($this->moderator(), $post), quietly: true) ? 1 : 0;
        }

        $this->selected = [];
        $this->toast($approved.' '.Str::plural('post', $approved).' approved and published'.self::skipped($posts->count() - $approved));
    }

    public function askToReject(int $id): void
    {
        $this->ask('reject', [$id]);
    }

    public function askToHide(int $id): void
    {
        $this->ask('hide', [$id]);
    }

    public function askToHideSelected(): void
    {
        $ids = app(ModerationPostsQuery::class)->among($this->selectedIds())->where('posts.status', PostStatus::Published)->pluck('posts.id')->map(fn (mixed $id): int => (int) $id)->all();

        if ($ids !== []) {
            $this->ask('hide', $ids);
        }
    }

    public function askToFinalize(int $id): void
    {
        $post = $this->find($id);

        if ($post !== null && $this->moderator()->can('finalizeRemoval', $post)) {
            $this->ask('finalize', [$id]);
        }
    }

    public function closeConfirmation(): void
    {
        $this->confirming = null;
        $this->reason = '';
        $this->acknowledged = false;
        $this->resetErrorBag();
    }

    /** Does what the confirmation on screen confirms, with its reason. */
    public function confirm(): void
    {
        if ($this->confirming === null) {
            return;
        }

        ['action' => $action, 'ids' => $ids] = $this->confirming;

        $this->validate(
            [
                'reason' => [$action === 'reject' ? 'nullable' : 'required', 'string', 'min:3', 'max:'.self::REASON_LIMIT],
                'acknowledged' => $action === 'finalize' ? ['accepted'] : [],
            ],
            [
                'reason.required' => $action === 'finalize' ? 'Give the internal reason.' : 'Give the reason, or choose one above.',
                'reason.min' => 'Say a little more: at least 3 characters.',
                'reason.max' => 'Keep it to '.number_format(self::REASON_LIMIT).' characters or fewer.',
                'acknowledged.accepted' => 'Tick the box to confirm this can’t be undone.',
            ],
        );

        $reason = trim($this->reason) !== '' ? trim($this->reason) : null;
        $posts = app(ModerationPostsQuery::class)->among($ids)->get();
        $done = [];

        foreach ($posts as $post) {
            $succeeded = $this->attempt(fn () => match ($action) {
                'reject' => app(RejectPostAction::class)->handle($this->moderator(), $post, $reason),
                'hide' => app(HidePostAction::class)->handle($this->moderator(), $post, $reason),
                'finalize' => app(FinalizePostRemovalAction::class)->handle($this->moderator(), $post, (string) $reason),
            }, quietly: count($ids) > 1);

            if ($succeeded) {
                $done[] = $post;
            }
        }

        $this->closeConfirmation();
        $this->selected = [];

        if ($done === []) {
            return;
        }

        $one = count($ids) === 1;
        $doneIds = array_map(fn (Post $post): int => (int) $post->id, $done);

        match ($action) {
            'reject' => $this->toast('“'.self::shortTitle($done[0]).'” rejected'),
            'finalize' => $this->toast('Removal of #'.$done[0]->id.' finalized'),
            'hide' => $this->toast(
                ($one ? '“'.self::shortTitle($done[0]).'” hidden' : count($done).' posts hidden').self::skipped(count($ids) - count($done)),
                ['label' => 'Undo', 'event' => 'restore-posts', 'params' => ['ids' => $doneIds]],
            ),
        };
    }

    public function restore(int $id): void
    {
        $post = $this->find($id);

        if ($post === null || ! $this->attempt(fn () => app(RestorePostAction::class)->handle($this->moderator(), $post))) {
            return;
        }

        $this->toast(
            '“'.self::shortTitle($post).'” restored and visible again',
            ['label' => 'Undo', 'event' => 'hide-posts-again', 'params' => ['ids' => [$id]]],
        );
    }

    /**
     * Undo after hiding: the posts are published again.
     *
     * @param  list<int|string>  $ids
     */
    #[On('restore-posts')]
    public function restorePosts(array $ids): void
    {
        $posts = app(ModerationPostsQuery::class)->among(self::ids($ids))->get();
        $restored = 0;

        foreach ($posts as $post) {
            $restored += $this->attempt(fn () => app(RestorePostAction::class)->handle($this->moderator(), $post), quietly: true) ? 1 : 0;
        }

        if ($restored > 0) {
            $this->toast($restored === 1 ? '“'.self::shortTitle($posts->first()).'” is visible again' : $restored.' posts are visible again');
        }
    }

    /**
     * Undo after restoring: the posts are hidden again.
     *
     * @param  list<int|string>  $ids
     */
    #[On('hide-posts-again')]
    public function hidePostsAgain(array $ids): void
    {
        $posts = app(ModerationPostsQuery::class)->among(self::ids($ids))->get();
        $hidden = 0;

        foreach ($posts as $post) {
            $hidden += $this->attempt(fn () => app(HidePostAction::class)->handle($this->moderator(), $post), quietly: true) ? 1 : 0;
        }

        if ($hidden > 0) {
            $this->toast($hidden === 1 ? '“'.self::shortTitle($posts->first()).'” is hidden again' : $hidden.' posts are hidden again');
        }
    }

    public function view(int $id): void
    {
        if ($this->find($id)?->imageAsset !== null) {
            $this->viewing = $id;
        }
    }

    public function closeViewer(): void
    {
        $this->viewing = null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $query = app(ModerationPostsQuery::class);
        $counts = $query->counts();
        $categories = Category::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'slug']);
        $paginator = $query->paginate((string) $this->status, (string) $this->q, $this->categoryId(), $this->order(), (int) $this->pageNumber);
        $oldest = $query->oldestPendingAt();

        return [
            'tabs' => collect(self::TABS)->map(fn (string $label, string $id): array => [
                'id' => $id,
                'label' => $label,
                'count' => number_format($counts[$id]),
                'href' => PostResource::getUrl('index', $id === 'pending' ? [] : ['status' => $id]),
                'attributes' => ['wire:click.prevent' => "\$set('status', '{$id}')"],
            ])->values()->all(),
            'stats' => [
                'waiting' => number_format($counts['pending']),
                'oldest' => $oldest !== null ? ModerationListFormat::age($oldest) : '—',
                'reported' => number_format($counts['reported']),
            ],
            'categories' => $categories->map(fn (Category $category): array => ['value' => $category->slug, 'label' => $category->name])->all(),
            'categoryName' => $categories->firstWhere('slug', $this->category)?->name,
            'order' => $this->order(),
            'paginator' => $paginator,
            'rows' => array_map(fn (Post $post): array => $this->row($post), $paginator->items()),
            'filtered' => trim((string) $this->q) !== '' || $this->category !== '',
            'selection' => $this->selection(),
            'confirmation' => $this->confirmation(),
            'reasons' => array_map(
                fn (ReportReason $reason): string => Str::ucfirst(str_replace('_', ' ', $reason->value)),
                array_filter(ReportReason::cases(), fn (ReportReason $reason): bool => $reason !== ReportReason::Other),
            ),
            'viewer' => $this->viewer(),
        ];
    }

    /**
     * One post as its row shows it.
     *
     * @return array<string, mixed>
     */
    private function row(Post $post): array
    {
        $author = $post->user;
        $pending = $post->status === PostStatus::Pending;
        $waited = $post->created_at !== null ? (int) $post->created_at->diffInMinutes(now(), true) : 0;
        $finalized = $post->isModerationRemovalFinalized();
        $canFinalize = $this->moderator()->can('finalizeRemoval', $post);
        $url = $post->status === PostStatus::Published && ! $post->trashed() ? route('posts.show', $post) : null;
        $authorUrl = $author !== null && UserResource::canEdit($author) ? UserResource::getUrl('edit', ['record' => $author]) : null;
        // Finalizing is an administrator's, and only a hidden post's.
        $finalize = match (true) {
            ! $canFinalize || $finalized || $post->trashed() => null,
            $post->status === PostStatus::Hidden => 'available',
            $post->status === PostStatus::Published => 'hide first',
            default => null,
        };

        return [
            'id' => (int) $post->id,
            'title' => $post->title,
            'meta' => '#'.$post->id.($post->category !== null ? ' · '.$post->category->name : ''),
            'thumbnail' => app(PostImagePresenter::class)->thumbnailUrl($post),
            'hasImage' => $post->imageAsset !== null,
            'url' => $url,
            'muted' => $post->trashed() || $finalized,
            'author' => $author === null ? null : [
                'name' => (string) ($author->username ?? $author->name),
                'initials' => ModerationListFormat::initials((string) ($author->username ?? $author->name)),
                'meta' => self::trust($author).' · '.number_format((int) $author->posts_count).' '.Str::plural('post', (int) $author->posts_count),
                'url' => $authorUrl,
            ],
            'status' => self::status($post),
            'reports' => (int) $post->reports_count,
            'created' => $post->created_at !== null ? ModerationListFormat::when($post->created_at) : '—',
            'waiting' => $pending ? 'Waiting '.ModerationListFormat::age($post->created_at ?? now()) : null,
            'due' => $pending && $waited >= self::DUE_AFTER_MINUTES,
            'approve' => $pending,
            'reject' => $pending,
            'hide' => $post->status === PostStatus::Published && ! $post->trashed(),
            'restore' => $post->status === PostStatus::Hidden && ! $finalized && ! $post->trashed() && $this->moderator()->can('restore', $post),
            'final' => $finalized,
            'finalize' => $finalize,
            'menu' => $url !== null || $post->imageAsset !== null || $authorUrl !== null || $finalize !== null,
            'selected' => in_array((int) $post->id, $this->selectedIds(), true),
        ];
    }

    /**
     * What the selected posts can have done to them: approving the pending
     * ones, hiding the published ones, and how many are neither.
     *
     * @return array{count: int, pending: int, published: int, skipped: int}|null
     */
    private function selection(): ?array
    {
        $ids = $this->selectedIds();

        if ($ids === []) {
            return null;
        }

        $statuses = app(ModerationPostsQuery::class)->among($ids)->get(['posts.id', 'posts.status', 'posts.deleted_at'])
            ->map(fn (Post $post): ?PostStatus => $post->trashed() ? null : $post->status);
        $pending = $statuses->filter(fn (?PostStatus $status): bool => $status === PostStatus::Pending)->count();
        $published = $statuses->filter(fn (?PostStatus $status): bool => $status === PostStatus::Published)->count();

        return [
            'count' => count($ids),
            'pending' => $pending,
            'published' => $published,
            'skipped' => count($ids) - $pending - $published,
        ];
    }

    /**
     * The confirmation on screen, with the posts it is about as they are now.
     *
     * @return array{action: string, posts: list<array{id: int, title: string, meta: string}>}|null
     */
    private function confirmation(): ?array
    {
        if ($this->confirming === null) {
            return null;
        }

        $posts = app(ModerationPostsQuery::class)->among($this->confirming['ids'])->with('user')->orderBy('posts.id')->get();

        if ($posts->isEmpty()) {
            return null;
        }

        return [
            'action' => $this->confirming['action'],
            'posts' => $posts->map(fn (Post $post): array => [
                'id' => (int) $post->id,
                'title' => $post->title,
                'meta' => implode(' · ', array_filter([
                    '#'.$post->id,
                    $post->user?->username,
                    $this->confirming['action'] === 'hide'
                        ? number_format((int) $post->reports_count).' '.Str::plural('report', (int) $post->reports_count)
                        : ($this->confirming['action'] === 'finalize' ? self::status($post)['label'] : ($post->created_at !== null ? ModerationListFormat::when($post->created_at) : null)),
                ])),
            ])->all(),
        ];
    }

    /**
     * The original image of the post being viewed, and what it is.
     *
     * @return array{title: string, url: string, meta: string}|null
     */
    private function viewer(): ?array
    {
        $post = $this->viewing !== null ? $this->find($this->viewing) : null;
        $asset = $post?->imageAsset;
        $url = $post !== null ? app(PostImagePresenter::class)->url($post) : null;

        if ($post === null || $asset === null || $url === null) {
            return null;
        }

        return [
            'title' => $post->title,
            'url' => $url,
            'meta' => implode(' · ', array_filter([
                '#'.$post->id,
                $asset->width !== null && $asset->height !== null ? $asset->width.' × '.$asset->height : null,
                Str::upper(Str::after((string) $asset->mime_type, '/')),
                Number::fileSize((int) $asset->byte_size, precision: 1),
                $asset->disk,
            ])),
        ];
    }

    /**
     * The badge a post's status shows, and the note under it.
     *
     * @return array{label: string, tone: string, dot: bool, note: ?string}
     */
    private static function status(Post $post): array
    {
        return match (true) {
            $post->isAuthorDeleted() => ['label' => 'Deleted by author', 'tone' => 'outline', 'dot' => false, 'note' => 'Retained for audit'],
            $post->isModerationRemovalFinalized() => ['label' => 'Removal finalized', 'tone' => 'neutral', 'dot' => false, 'note' => 'Retained internally'],
            $post->status === PostStatus::Pending => ['label' => 'Pending', 'tone' => 'warning', 'dot' => false, 'note' => null],
            $post->status === PostStatus::Published => ['label' => 'Published', 'tone' => 'success', 'dot' => true, 'note' => null],
            $post->status === PostStatus::Hidden => ['label' => 'Hidden', 'tone' => 'neutral', 'dot' => false, 'note' => 'Restorable'],
            $post->status === PostStatus::Rejected => ['label' => 'Rejected', 'tone' => 'danger', 'dot' => false, 'note' => $post->getAttribute('rejection_reason')],
            default => ['label' => Str::ucfirst($post->status->value), 'tone' => 'outline', 'dot' => false, 'note' => null],
        };
    }

    /** Whether an author publishes at once: trusted, and — unless staff — with a confirmed email address. */
    private static function trust(User $author): string
    {
        return match (true) {
            (int) $author->trust_level < MarkUserTrustedAction::TRUSTED_LEVEL => 'Not trusted',
            $author->role === UserRole::User && ! $author->hasVerifiedEmail() => 'Email not confirmed',
            default => 'Trusted',
        };
    }

    private static function shortTitle(?Post $post): string
    {
        return Str::limit((string) $post?->title, 38, '…');
    }

    private static function skipped(int $skipped): string
    {
        return $skipped > 0 ? ' · '.$skipped.' skipped: '.($skipped === 1 ? 'it' : 'they').' changed meanwhile' : '';
    }

    /**
     * @param  array<mixed>  $ids
     * @return list<int>
     */
    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_map(intval(...), array_filter($ids, fn (mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit($id))))));
    }

    /** @return list<int> */
    private function selectedIds(): array
    {
        return self::ids($this->selected);
    }

    /** @param list<int> $ids */
    private function ask(string $action, array $ids): void
    {
        $this->confirming = ['action' => $action, 'ids' => $ids];
        $this->reason = '';
        $this->acknowledged = false;
        $this->resetErrorBag();
    }

    private function find(int $id): ?Post
    {
        return app(ModerationPostsQuery::class)->among([$id])->with(['user', 'imageAsset'])->first();
    }

    /**
     * Runs one moderation action, and says in a toast why it did not happen:
     * someone else moderated the post meanwhile, or this moderator may not.
     */
    private function attempt(callable $action, bool $quietly = false): bool
    {
        try {
            $action();

            return true;
        } catch (CannotModeratePostException|CannotFinalizeRemovalException $exception) {
            if (! $quietly) {
                $this->toast('This post changed meanwhile, so nothing was done. The list now shows it as it is.', tone: 'error');
            }

            return false;
        }
    }

    /** @param array{label: string, event: string, params: array<string, mixed>}|null $action */
    private function toast(string $message, ?array $action = null, string $tone = 'success'): void
    {
        $this->dispatch('rg-admin-toast', message: $message, tone: $tone, action: $action);
    }

    private function moderator(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function order(): string
    {
        return array_key_exists((string) $this->sort, self::SORTS) ? (string) $this->sort : ($this->status === 'pending' ? 'oldest' : 'newest');
    }

    private function categoryId(): ?int
    {
        if ($this->category === '') {
            return null;
        }

        $id = Category::query()->where('slug', $this->category)->value('id');

        return $id !== null ? (int) $id : null;
    }

    /** Whatever a link or a forged update put here, read as something the page offers. */
    private function normalize(): void
    {
        $this->status = array_key_exists((string) $this->status, self::TABS) ? (string) $this->status : 'pending';
        $this->q = is_string($this->q) ? mb_substr($this->q, 0, 100) : '';
        $this->category = is_string($this->category) && Category::query()->where('slug', $this->category)->exists() ? $this->category : '';
        $this->sort = array_key_exists((string) $this->sort, self::SORTS) ? (string) $this->sort : '';
        $this->pageNumber = max(1, is_numeric($this->pageNumber) ? (int) $this->pageNumber : 1);
        $this->selected = array_map(strval(...), $this->selectedIds());
    }
}
