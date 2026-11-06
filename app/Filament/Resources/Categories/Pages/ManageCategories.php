<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Actions\Categories\CreateCategoryAction;
use App\Actions\Categories\DeleteCategoryAction;
use App\Actions\Categories\UpdateCategoryAction;
use App\Exceptions\Categories\CannotDeleteCategoryException;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Concerns\EditsTaxonomyInDrawer;
use App\Filament\Support\ContentTranslationStatus;
use App\Models\Category;
use App\Models\Post;
use App\Support\Translations\ProjectContentSection;
use App\Support\Translations\ProjectTranslationCatalog;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;

/**
 * Categories, drawn entirely in Admin v2: every category in its curated
 * order with its translation status, created and edited in a drawer,
 * activated and deactivated from its row menu with Undo, and deleted only
 * while no post — not even a deleted one that is kept — is filed under it.
 *
 * The list is bounded and already on the page, so the search is the
 * browser's. Every write goes through the category actions, which check the
 * policy again; what the browser sends is never a permission.
 *
 * @phpstan-import-type Chip from ContentTranslationStatus
 *
 * @phpstan-type CategoryRow array{id: int, name: string, slug: string, active: bool, sort: int, posts: int, retained: bool, chips: list<Chip>, missing: int, search: string, translate: ?string, url: string}
 */
final class ManageCategories extends Page
{
    use EditsTaxonomyInDrawer;

    /** The largest sort order the editor takes. */
    public const MAX_SORT_ORDER = 999999;

    protected static string $resource = CategoryResource::class;

    protected string $view = 'filament.pages.categories';

    // A screen drawn in Admin v2 brings its own header band and gutter.
    protected Width|string|null $maxContentWidth = 'rg-admin-main';

    protected static ?string $title = 'Categories';

    public mixed $draftSortOrder = 0;

    public bool $draftActive = true;

    /** @var array<int, CategoryRow>|null what this request read, shared by the header, the table and the overlays */
    private ?array $rows = null;

    public static function canAccess(array $parameters = []): bool
    {
        return CategoryResource::canAccess();
    }

    public function mount(): void
    {
        $id = self::recordId($this->editing);
        $this->editing = null;

        if ($id !== null) {
            $this->edit($id);
        }
    }

    /** Opens the drawer on a new category, named already when it comes from a search that found nothing. */
    #[On('create-category')]
    public function create(mixed $name = null): void
    {
        abort_unless(CategoryResource::canCreate(), 403);

        $this->closeConfirmation();
        $this->closeDrawer();
        $this->creating = true;
        $this->slugGenerated = true;
        $this->draftName = is_string($name) ? Str::limit(Str::squish($name), self::nameLimit(), '') : '';
        $this->draftSlug = Str::slug($this->draftName);
        // Last, after every category there is.
        $this->draftSortOrder = min(self::MAX_SORT_ORDER, (int) Category::query()->max('sort_order') + 1);
        $this->draftActive = true;
    }

    public function edit(mixed $id = null): void
    {
        $this->closeConfirmation();
        $category = $this->find($id);

        if ($category === null) {
            $this->refuseMissing();

            return;
        }

        $this->closeDrawer();
        $this->editing = $category->id;
        $this->draftName = $category->name;
        $this->draftSlug = $category->slug;
        $this->draftSortOrder = $category->sort_order;
        $this->draftActive = $category->is_active;
    }

    public function save(): void
    {
        $category = null;

        if (! $this->creating) {
            $category = $this->find($this->editing);

            if ($category === null) {
                $this->closeDrawer();
                $this->refuseMissing();

                return;
            }

            // Changed only once unlocked: anything else that arrives for it is
            // not a change anybody asked for.
            if (! $this->slugUnlocked) {
                $this->draftSlug = $category->slug;
            }
        }

        $this->draftName = Str::squish($this->draftName);
        $this->draftSlug = trim($this->draftSlug);
        $this->draftSortOrder = is_string($this->draftSortOrder) ? trim($this->draftSortOrder) : $this->draftSortOrder;

        $this->validate([
            'draftName' => ['required', 'string', 'max:'.self::nameLimit()],
            'draftSlug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique(Category::class, 'slug')->ignore($category?->id)],
            'draftSortOrder' => ['required', 'integer', 'between:0,'.self::MAX_SORT_ORDER],
            'draftActive' => ['boolean'],
        ], [
            'draftName.required' => 'Enter a name.',
            'draftName.max' => 'Keep the name to '.self::nameLimit().' characters or fewer.',
            'draftSlug.required' => 'Enter a slug.',
            'draftSlug.max' => 'Keep the slug to 100 characters or fewer.',
            'draftSlug.regex' => 'Use lowercase letters and numbers, with single hyphens between words: small-pets.',
            'draftSlug.unique' => 'This slug is already used by another category. Choose another one.',
            'draftSortOrder.*' => 'Enter a whole number from 0 to '.number_format(self::MAX_SORT_ORDER).'.',
        ]);

        $data = [
            'name' => $this->draftName,
            'slug' => $this->draftSlug,
            'sort_order' => (int) $this->draftSortOrder,
            'is_active' => $this->draftActive,
        ];

        if ($category === null) {
            $created = app(CreateCategoryAction::class)->handle($this->actor(), $data);
            $this->toast($this->createdMessage($created->name));
        } else {
            app(UpdateCategoryAction::class)->handle($this->actor(), $category, $data);
            $this->toast("{$category->name} saved");
        }

        $this->closeDrawer();
    }

    /** Shows a category to visitors again. From a toast's Undo it says so without offering another. */
    #[On('activate-category')]
    public function activate(mixed $id = null, mixed $undo = false): void
    {
        $this->setActive($id, true, $undo === true);
    }

    /** Hides a category from navigation and the upload form; its posts keep it. */
    #[On('deactivate-category')]
    public function deactivate(mixed $id = null, mixed $undo = false): void
    {
        $this->setActive($id, false, $undo === true);
    }

    /**
     * Asks before deleting. What the dialog says is read when it is drawn,
     * so a category that gained a post meanwhile is explained, not offered.
     */
    public function askToDelete(mixed $id = null): void
    {
        $category = $this->find($id);

        if ($category === null) {
            $this->closeConfirmation();
            $this->refuseMissing();

            return;
        }

        // A dialog opened from the drawer replaces it.
        $this->closeDrawer();
        $this->deleting = $category->id;
    }

    public function delete(mixed $id = null): void
    {
        $this->closeConfirmation();
        $category = $this->find($id);

        if ($category === null) {
            $this->refuseMissing();

            return;
        }

        try {
            app(DeleteCategoryAction::class)->handle($this->actor(), $category);
        } catch (CannotDeleteCategoryException $exception) {
            if ($exception->reason === CannotDeleteCategoryException::REASON_USED_BY_POSTS) {
                // A post arrived after the dialog was drawn: it says why now.
                $this->deleting = $category->id;

                return;
            }

            $this->toast('You are not allowed to delete categories.', 'error');

            return;
        }

        $this->rows = null;
        $this->toast("{$category->name} deleted");
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $rows = $this->categories();
        $status = app(ContentTranslationStatus::class);
        $active = array_filter($rows, fn (array $row): bool => $row['active']);

        return [
            'rows' => $rows,
            'stats' => [
                'active' => count($active),
                'inactive' => count($rows) - count($active),
                // Over active categories: an inactive one is not translated while visitors cannot see it.
                'missing' => array_sum(array_map(fn (array $row): int => $row['missing'], $active)),
            ],
            'translationCenter' => $status->canTranslate() ? ContentTranslationStatus::sectionUrl(ProjectContentSection::Categories) : null,
            'drawer' => $this->drawer($rows, $status),
            'confirmation' => $rows[$this->deleting ?? 0] ?? null,
            'nameLimit' => self::nameLimit(),
        ];
    }

    /** The English name is held to what its translations are held to. */
    private static function nameLimit(): int
    {
        return ProjectTranslationCatalog::FIELDS['categories']['name'][0];
    }

    private function setActive(mixed $id, bool $active, bool $undo): void
    {
        $this->closeConfirmation();
        $category = $this->find($id);

        if ($category === null) {
            $this->refuseMissing();

            return;
        }

        if ($category->is_active === $active) {
            $this->toast("{$category->name} is already ".($active ? 'active' : 'inactive').'.', 'info');

            return;
        }

        app(UpdateCategoryAction::class)->handle($this->actor(), $category, ['is_active' => $active]);
        $this->rows = null;

        // The drawer open on it shows what was just saved.
        if ($this->editing === $category->id) {
            $this->draftActive = $active;
        }

        if ($undo) {
            $this->toast("{$category->name} is ".($active ? 'active' : 'inactive').' again');

            return;
        }

        $this->toast(
            "{$category->name} ".($active ? 'activated' : 'deactivated'),
            action: ['label' => 'Undo', 'event' => $active ? 'deactivate-category' : 'activate-category', 'params' => ['id' => $category->id, 'undo' => true]],
        );
    }

    private function refuseMissing(): void
    {
        $this->rows = null;
        $this->toast('That category no longer exists.', 'error');
    }

    private function find(mixed $id): ?Category
    {
        $id = self::recordId($id);

        return $id === null ? null : Category::query()->find($id);
    }

    /**
     * Every category in its curated order, each with its post count, whether
     * any post at all — a deleted one that is kept included — still holds it,
     * and its translation status.
     *
     * @return array<int, CategoryRow>
     */
    private function categories(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        $status = app(ContentTranslationStatus::class);
        $translate = $status->canTranslate();

        return $this->rows = Category::query()
            ->withCount('posts')
            ->withExists(['posts as retained' => self::evenDeleted(...)])
            ->ordered()
            ->get()
            ->mapWithKeys(function (Category $category) use ($status, $translate): array {
                $chips = $status->chips($category->name_translations);

                return [$category->id => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'active' => $category->is_active,
                    'sort' => $category->sort_order,
                    'posts' => (int) $category->posts_count,
                    'retained' => (bool) $category->getAttribute('retained'),
                    'chips' => $chips,
                    'missing' => $category->is_active ? ContentTranslationStatus::missing($chips) : 0,
                    'search' => mb_strtolower("{$category->name} {$category->slug}"),
                    // Translation Center lists what visitors can see: an inactive category waits until it is active again.
                    'translate' => $translate && $category->is_active ? $status->translateUrl(ProjectContentSection::Categories, $category->id, ['name' => $chips]) : null,
                    'url' => self::getUrl(['edit' => $category->id]),
                ]];
            })
            ->all();
    }

    /**
     * Every post, a deleted one that is kept included: the foreign key does
     * not care about the scope, so a retained author-deleted post still keeps
     * its category. A named method rather than a closure, so the generic
     * annotation has somewhere to go.
     *
     * @param  Builder<Post>  $posts
     * @return Builder<Post>
     */
    private static function evenDeleted(Builder $posts): Builder
    {
        return $posts->withTrashed();
    }

    /**
     * What the drawer shows: the category it edits as it is now, or none
     * while it creates one.
     *
     * @param  array<int, CategoryRow>  $rows
     * @return array{row: ?CategoryRow, chips: list<Chip>, translate: ?string}|null
     */
    private function drawer(array $rows, ContentTranslationStatus $status): ?array
    {
        if ($this->creating) {
            return ['row' => null, 'chips' => $status->chips(null), 'translate' => null];
        }

        $row = $rows[$this->editing ?? 0] ?? null;

        return $row === null ? null : ['row' => $row, 'chips' => $row['chips'], 'translate' => $row['translate']];
    }
}
