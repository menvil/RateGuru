<?php

namespace App\Filament\Resources\Tags\Pages;

use App\Actions\Tags\CreateTagAction;
use App\Actions\Tags\DeleteTagAction;
use App\Actions\Tags\UpdateTagAction;
use App\Exceptions\Tags\CannotDeleteTagException;
use App\Filament\Resources\Concerns\EditsTaxonomyInDrawer;
use App\Filament\Resources\Tags\TagResource;
use App\Filament\Support\ContentTranslationStatus;
use App\Models\Tag;
use App\Rules\UniqueEffectiveTagSlug;
use App\Support\Translations\ProjectContentSection;
use App\Support\Translations\ProjectTranslationCatalog;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Support\Str;
use Livewire\Attributes\On;

/**
 * Tags, drawn entirely in Admin v2: every tag, most used first, with its
 * translation status, created and edited in a drawer, and deleted only
 * while no post carries it.
 *
 * Moderators curate tags with administrators; only an administrator deletes
 * one, and only an administrator opens Translation Center. Merge into… is
 * in the row menu, unavailable: moving one tag's posts to another is a
 * feature still to be designed, and the menu says so rather than hiding it.
 *
 * The list is bounded and already on the page, so the search is the
 * browser's. Every write goes through the tag actions, which check the
 * policy again; what the browser sends is never a permission.
 *
 * @phpstan-import-type Chip from ContentTranslationStatus
 *
 * @phpstan-type TagRow array{id: int, name: string, slug: string, posts: int, chips: list<Chip>, missing: int, search: string, translate: ?string, url: string}
 */
final class ManageTags extends Page
{
    use EditsTaxonomyInDrawer;

    protected static string $resource = TagResource::class;

    protected string $view = 'filament.pages.tags';

    // A screen drawn in Admin v2 brings its own header band and gutter.
    protected Width|string|null $maxContentWidth = 'rg-admin-main';

    protected static ?string $title = 'Tags';

    /** @var array<int, TagRow>|null what this request read, shared by the header, the table and the overlays */
    private ?array $rows = null;

    public static function canAccess(array $parameters = []): bool
    {
        return TagResource::canAccess();
    }

    public function mount(): void
    {
        $id = self::recordId($this->editing);
        $this->editing = null;

        if ($id !== null) {
            $this->edit($id);
        }
    }

    /** Opens the drawer on a new tag, named already when it comes from a search that found nothing. */
    #[On('create-tag')]
    public function create(mixed $name = null): void
    {
        abort_unless(TagResource::canCreate(), 403);

        $this->closeConfirmation();
        $this->closeDrawer();
        $this->creating = true;
        $this->slugGenerated = true;
        $this->draftName = is_string($name) ? Str::limit(Str::squish($name), self::nameLimit(), '') : '';
        $this->draftSlug = Str::slug($this->draftName);
    }

    public function edit(mixed $id = null): void
    {
        $this->closeConfirmation();
        $tag = $this->find($id);

        if ($tag === null) {
            $this->refuseMissing();

            return;
        }

        abort_unless(TagResource::canEdit($tag), 403);

        $this->closeDrawer();
        $this->editing = $tag->id;
        $this->draftName = $tag->name;
        $this->draftSlug = $tag->slug;
    }

    public function save(): void
    {
        $tag = null;

        if (! $this->creating) {
            $tag = $this->find($this->editing);

            if ($tag === null) {
                $this->closeDrawer();
                $this->refuseMissing();

                return;
            }

            // Changed only once unlocked: anything else that arrives for it is
            // not a change anybody asked for.
            if (! $this->slugUnlocked) {
                $this->draftSlug = $tag->slug;
            }
        }

        $this->draftName = Str::squish($this->draftName);
        $this->draftSlug = trim($this->draftSlug);

        $this->validate([
            'draftName' => ['required', 'string', 'max:'.self::nameLimit()],
            // Optional: left blank, the slug is made from the name. Unique as it
            // will be saved, the made one included.
            'draftSlug' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', new UniqueEffectiveTagSlug($this->draftName, $tag?->id)],
        ], [
            'draftName.required' => 'Enter a name.',
            'draftName.max' => 'Keep the name to '.self::nameLimit().' characters or fewer.',
            'draftSlug.max' => 'Keep the slug to 100 characters or fewer.',
            'draftSlug.regex' => 'Use lowercase letters and numbers, with single hyphens between words: best-friends.',
        ]);

        $data = ['name' => $this->draftName, 'slug' => $this->draftSlug === '' ? null : $this->draftSlug];

        if ($tag === null) {
            $created = app(CreateTagAction::class)->handle($this->actor(), $data);
            $this->toast($this->createdMessage("#{$created->name}"));
        } else {
            app(UpdateTagAction::class)->handle($this->actor(), $tag, $data);
            $this->toast("#{$tag->name} saved");
        }

        $this->closeDrawer();
    }

    /**
     * Asks before deleting. What the dialog says is read when it is drawn, so
     * a tag a post took meanwhile is explained, not offered.
     */
    public function askToDelete(mixed $id = null): void
    {
        $tag = $this->find($id);

        if ($tag === null) {
            $this->closeConfirmation();
            $this->refuseMissing();

            return;
        }

        if (! $this->actor()->can('delete', $tag)) {
            $this->toast('Only administrators delete tags.', 'error');

            return;
        }

        // A dialog opened from the drawer replaces it.
        $this->closeDrawer();
        $this->deleting = $tag->id;
    }

    public function delete(mixed $id = null): void
    {
        $this->closeConfirmation();
        $tag = $this->find($id);

        if ($tag === null) {
            $this->refuseMissing();

            return;
        }

        try {
            app(DeleteTagAction::class)->handle($this->actor(), $tag);
        } catch (CannotDeleteTagException $exception) {
            if ($exception->reason === CannotDeleteTagException::REASON_USED_BY_POSTS) {
                // A post took it after the dialog was drawn: it says why now.
                $this->deleting = $tag->id;

                return;
            }

            $this->toast('Only administrators delete tags.', 'error');

            return;
        }

        $this->rows = null;
        $this->toast("#{$tag->name} deleted");
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $rows = $this->tags();
        $status = app(ContentTranslationStatus::class);

        return [
            'rows' => $rows,
            'stats' => [
                'tags' => count($rows),
                'unused' => count(array_filter($rows, fn (array $row): bool => $row['posts'] === 0)),
                'missing' => array_sum(array_map(fn (array $row): int => $row['missing'], $rows)),
            ],
            'translationCenter' => $status->canTranslate() ? ContentTranslationStatus::sectionUrl(ProjectContentSection::Tags) : null,
            'canCreate' => TagResource::canCreate(),
            'canDelete' => $this->actor()->can('delete', new Tag),
            'drawer' => $this->drawer($rows, $status),
            'confirmation' => $rows[$this->deleting ?? 0] ?? null,
            'nameLimit' => self::nameLimit(),
        ];
    }

    /** The English name is held to what its translations are held to. */
    private static function nameLimit(): int
    {
        return ProjectTranslationCatalog::FIELDS['tags']['name'][0];
    }

    private function refuseMissing(): void
    {
        $this->rows = null;
        $this->toast('That tag no longer exists.', 'error');
    }

    private function find(mixed $id): ?Tag
    {
        $id = self::recordId($id);

        return $id === null ? null : Tag::query()->find($id);
    }

    /**
     * Every tag, the most used first, each with its post count and its
     * translation status.
     *
     * @return array<int, TagRow>
     */
    private function tags(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        $status = app(ContentTranslationStatus::class);
        $translate = $status->canTranslate();

        return $this->rows = Tag::query()
            ->withCount('posts')
            ->orderByDesc('posts_count')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(function (Tag $tag) use ($status, $translate): array {
                $chips = $status->chips($tag->name_translations);

                return [$tag->id => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'slug' => $tag->slug,
                    'posts' => (int) $tag->posts_count,
                    'chips' => $chips,
                    'missing' => ContentTranslationStatus::missing($chips),
                    'search' => mb_strtolower("{$tag->name} {$tag->slug}"),
                    'translate' => $translate ? $status->translateUrl(ProjectContentSection::Tags, $tag->id, ['name' => $chips]) : null,
                    'url' => self::getUrl(['edit' => $tag->id]),
                ]];
            })
            ->all();
    }

    /**
     * What the drawer shows: the tag it edits as it is now, or none while it
     * creates one.
     *
     * @param  array<int, TagRow>  $rows
     * @return array{row: ?TagRow, chips: list<Chip>, translate: ?string}|null
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
