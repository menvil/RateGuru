<?php

namespace App\Filament\Resources\StudioPlans\Concerns;

use App\Actions\Import\StoreImportedImageAction;
use App\Actions\Studio\RemoveStudioPlanImageAction;
use App\Actions\Studio\SetStudioPlanImageAction;
use App\Data\Studio\StudioPlanData;
use App\Enums\ImageInputSource;
use App\Enums\StudioPlanPublicationMode;
use App\Exceptions\Studio\CannotManageStudioPlanException;
use App\Filament\Resources\StudioPlans\StudioPlanFormat;
use App\Filament\Resources\StudioPlans\StudioPlanResource;
use App\Models\Category;
use App\Models\MediaAsset;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\StudioPlan;
use App\Models\Tag;
use App\Models\User;
use App\Queries\Categories\ActiveCategoriesQuery;
use App\Queries\Personas\PersonasQuery;
use App\Services\Media\Exceptions\ImageIngestException;
use App\Support\Media\PostImagePresenter;
use App\Support\Posts\PostContentRules;
use App\Support\Rating\RatingConfigurationManager;
use App\Support\Studio\StudioPlanValidationRules;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

/**
 * A publication plan as its editor holds it (CreateStudioPlan,
 * EditStudioPlan), in four steps — Image, Post, Discussion and Publication —
 * that only change what is on screen: moving between them saves nothing and
 * loses nothing.
 *
 * What is typed or chosen is a draft until Save in the top bar (NAV-02): the
 * browser compares it to `saved` to count what is unsaved, and Discard puts
 * the saved values back. A photo chosen from the computer, a link to import
 * one from and taking the saved photo off are part of the draft; the file is
 * stored only when the draft is saved. The draft is checked with the rules
 * the Content Studio actions check it with again (StudioPlanValidationRules),
 * and nothing in it can carry a status, a creator or a version.
 */
trait EditsStudioPlan
{
    /** The editor's steps, in order: key => [label, what it holds]. */
    public const STEPS = [
        'image' => ['Image', 'Upload & analysis'],
        'post' => ['Post', 'Texts, author & votes'],
        'discussion' => ['Discussion', 'Comments & their votes'],
        'publication' => ['Publication', 'Plan & timeline'],
    ];

    /** The most Personas, and tags, offered at once to choose from. */
    private const CHOICES = 8;

    /** The step on screen. Not part of the draft. */
    public string $step = 'image';

    /** The post's title. Not `$title`, which is the page's own. */
    public string $postTitle = '';

    public string $postDescription = '';

    public string $sourceUrl = '';

    /** The chosen Persona's id, or '' for none. */
    public string $authorUserId = '';

    public string $categoryId = '';

    /** @var array<int|string, mixed> */
    public array $tagIds = [];

    /**
     * The author's answer in each rating group: group id => option id, '' for none.
     *
     * @var array<int|string, mixed>
     */
    public array $authorAnswers = [];

    public string $publicationMode = 'now';

    /** The date and time to publish at, as the date field holds it (Y-m-d\TH:i), in the application's time zone. */
    public string $publishAt = '';

    /**
     * A photo chosen from the computer, kept until Save.
     *
     * @var TemporaryUploadedFile|null
     */
    public $photo = null;

    /**
     * Where a new photo comes from: the computer, or a link. Only this source
     * is ever saved, and only its value counts as a change. Not part of the
     * draft.
     */
    public string $imageSource = 'upload';

    /** A direct link to an image to import when the draft is saved. */
    public string $imageUrl = '';

    /** Whether Save takes the saved photo off the plan. */
    public bool $removeImage = false;

    /** The search over the Personas offered as the author. Not part of the draft. */
    public string $authorSearch = '';

    /** The search over the tags offered. Not part of the draft. */
    public string $tagSearch = '';

    /**
     * The draft as saved, keyed as the browser compares it.
     *
     * @var array<string, string>
     */
    #[Locked]
    public array $saved = [];

    public static function canAccess(array $parameters = []): bool
    {
        return StudioPlanResource::canAccess();
    }

    /** Shows a step; nothing is saved and nothing typed is lost. */
    public function goToStep(mixed $step): void
    {
        if (is_string($step) && array_key_exists($step, self::STEPS)) {
            $this->step = $step;
        }
    }

    public function nextStep(): void
    {
        $this->goToStep($this->stepAt(1));
    }

    public function previousStep(): void
    {
        $this->goToStep($this->stepAt(-1));
    }

    /**
     * A chosen photo is checked as soon as it arrives, so a wrong file is said
     * before Save. A file comes from the computer, so the computer becomes the
     * source and a link typed before is dropped.
     */
    public function updatedPhoto(): void
    {
        $this->imageSource = 'upload';
        $this->dropImageUrl();
        $this->removeImage = false;
        $this->validateOnly('photo', $this->imageRules(), $this->imageMessages());
    }

    /** A link typed makes the link the source, and drops a file chosen before. */
    public function updatedImageUrl(): void
    {
        if (trim($this->imageUrl) === '') {
            return;
        }

        $this->imageSource = 'link';
        $this->dropPhoto();
        $this->removeImage = false;
    }

    /**
     * Only the source on screen is ever saved: switching drops what the other
     * one held — a file chosen before switching to a link, a link typed before
     * switching back — rather than keeping it out of sight to win on Save. The
     * saved photo stays as it is either way.
     */
    public function updatedImageSource(): void
    {
        $this->imageSource = $this->imageSource === 'link' ? 'link' : 'upload';

        $this->imageSource === 'link' ? $this->dropPhoto() : $this->dropImageUrl();
    }

    /** Takes the chosen photo off the draft; the saved one stays. */
    public function clearPhoto(): void
    {
        $this->dropPhoto();
    }

    /** Takes the link off the draft; the saved photo stays. */
    public function clearImageUrl(): void
    {
        $this->dropImageUrl();
    }

    /** Save takes the saved photo off the plan; until then it stays. */
    public function removeSavedImage(): void
    {
        $this->photo = null;
        $this->imageUrl = '';
        $this->removeImage = true;
        $this->resetErrorBag(['photo', 'imageUrl']);
    }

    public function keepSavedImage(): void
    {
        $this->removeImage = false;
    }

    /** Chooses a living Persona as the author. */
    public function chooseAuthor(mixed $id): void
    {
        $author = is_numeric($id) ? app(PersonasQuery::class)->choosable((int) $id) : null;

        if ($author === null) {
            $this->addError('authorUserId', 'Choose a persona as the author.');

            return;
        }

        $this->authorUserId = (string) $author->id;
        $this->authorSearch = '';
        $this->resetErrorBag('authorUserId');
    }

    public function clearAuthor(): void
    {
        $this->authorUserId = '';
        $this->resetErrorBag('authorUserId');
    }

    public function addTag(mixed $id): void
    {
        $ids = $this->chosenTagIds();

        if (! is_numeric($id) || count($ids) >= PostContentRules::MOST_TAGS || ! Tag::query()->whereKey((int) $id)->exists()) {
            return;
        }

        $this->tagIds = array_values(array_unique([...$ids, (int) $id]));
        $this->tagSearch = '';
    }

    public function removeTag(mixed $id): void
    {
        $this->tagIds = array_values(array_filter($this->chosenTagIds(), fn (int $chosen): bool => $chosen !== (int) $id));
    }

    /** Puts every value of the draft back as saved, and forgets what was chosen for the photo. */
    protected function fillDraft(StudioPlanData $data): void
    {
        $this->postTitle = (string) $data->title;
        $this->postDescription = (string) $data->description;
        $this->sourceUrl = (string) $data->sourceUrl;
        $this->authorUserId = $data->authorUserId !== null ? (string) $data->authorUserId : '';
        $this->categoryId = $data->categoryId !== null ? (string) $data->categoryId : '';
        $this->tagIds = array_values(array_map(intval(...), $data->tagIds));
        // Every active group has its place, so a group with no answer reads
        // as “No answer” rather than as nothing at all.
        $this->authorAnswers = app(RatingConfigurationManager::class)->activeGroups()
            ->mapWithKeys(fn (RatingGroup $group): array => [$group->id => ''])
            ->replace(RatingOption::query()
                ->whereKey($data->authorAnswerOptionIds)
                ->pluck('id', 'rating_group_id')
                ->map(fn (mixed $id): string => (string) $id))
            ->all();
        $this->publicationMode = $data->publicationMode->value;
        $this->publishAt = self::dateInput($data->publicationMode === StudioPlanPublicationMode::AtDate ? $data->publishAt : null);
        $this->photo = null;
        $this->imageUrl = '';
        $this->removeImage = false;
        $this->authorSearch = '';
        $this->tagSearch = '';
    }

    /**
     * A plan's values as the editor compares them: every value as text, each
     * list of ids in ascending order, and nothing chosen for the photo.
     *
     * @return array<string, string>
     */
    protected static function savedValuesOf(StudioPlanData $data): array
    {
        $ids = function (array $ids): string {
            $ids = array_map(intval(...), $ids);
            sort($ids);

            return implode(',', $ids);
        };

        return [
            'title' => (string) $data->title,
            'description' => (string) $data->description,
            'sourceUrl' => (string) $data->sourceUrl,
            'authorUserId' => $data->authorUserId !== null ? (string) $data->authorUserId : '',
            'categoryId' => $data->categoryId !== null ? (string) $data->categoryId : '',
            'tagIds' => $ids($data->tagIds),
            'authorAnswers' => $ids($data->authorAnswerOptionIds),
            'publicationMode' => $data->publicationMode->value,
            'publishAt' => self::dateInput($data->publicationMode === StudioPlanPublicationMode::AtDate ? $data->publishAt : null),
            'photo' => '',
            'imageUrl' => '',
            'removeImage' => '',
        ];
    }

    /**
     * What a plan's draft is, in one value: whether the texts and settings
     * differ from what was drawn, apart from the photo.
     */
    protected static function fingerprintOf(StudioPlanData $data): string
    {
        return hash('sha256', json_encode($data->normalized()->toArray(), JSON_THROW_ON_ERROR));
    }

    /**
     * Checks the draft with the actions' own rules, each message by its field,
     * every text first put as the actions store it — and shows the first step
     * with something to fix.
     *
     * @throws ValidationException
     */
    protected function validateDraft(): StudioPlanData
    {
        $this->postTitle = (string) StudioPlanData::normalizedText($this->postTitle);
        $this->postDescription = (string) StudioPlanData::normalizedText($this->postDescription);
        $this->sourceUrl = (string) StudioPlanData::normalizedText($this->sourceUrl);
        $this->imageUrl = trim($this->imageUrl);

        try {
            // What the editor's own fields can hold, before the plan's rules.
            $this->validate([
                'authorUserId' => ['nullable', 'regex:/\A[0-9]{1,18}\z/'],
                'categoryId' => ['nullable', 'regex:/\A[0-9]{1,18}\z/'],
                'tagIds' => ['array'],
                'tagIds.*' => ['regex:/\A[0-9]{1,18}\z/'],
                'authorAnswers' => ['array'],
                'authorAnswers.*' => ['nullable', 'regex:/\A[0-9]{0,18}\z/'],
                'publicationMode' => ['required', Rule::enum(StudioPlanPublicationMode::class)],
                'publishAt' => ['nullable', 'date_format:Y-m-d\TH:i,Y-m-d\TH:i:s'],
                // The source on screen alone: the other holds nothing to save.
                ...array_intersect_key($this->imageRules(), [$this->imageSource === 'link' ? 'imageUrl' : 'photo' => true]),
            ], [
                'authorUserId.*' => 'Choose a persona as the author.',
                'categoryId.*' => 'Choose a category from the list.',
                'tagIds.*' => 'Choose tags from the list.',
                'authorAnswers.*' => 'Choose the author’s answers from the rating options.',
                'publicationMode.*' => 'Choose when to publish.',
                'publishAt.*' => 'Enter a date and time.',
                ...$this->imageMessages(),
            ]);

            return app(StudioPlanValidationRules::class)->validated($this->draftData());
        } catch (ValidationException $exception) {
            $this->step = self::stepOf(array_key_first($exception->errors()) ?? '') ?? $this->step;

            throw $exception;
        }
    }

    /** The draft, as the Content Studio actions take it. Checked by validateDraft() first. */
    protected function draftData(): StudioPlanData
    {
        $id = fn (string $value): ?int => $value !== '' ? (int) $value : null;
        $mode = StudioPlanPublicationMode::from($this->publicationMode);

        return new StudioPlanData(
            authorUserId: $id($this->authorUserId),
            title: $this->postTitle,
            description: $this->postDescription,
            sourceUrl: $this->sourceUrl,
            categoryId: $id($this->categoryId),
            tagIds: array_values(array_map(intval(...), $this->tagIds)),
            authorAnswerOptionIds: array_values(array_map(
                intval(...),
                array_filter($this->authorAnswers, fn (mixed $option): bool => $option !== '' && $option !== null),
            )),
            publicationMode: $mode,
            publishAt: $mode === StudioPlanPublicationMode::AtDate ? self::parsedDate($this->publishAt) : null,
        );
    }

    /** Whether Save has anything to do with the photo. */
    protected function changesImage(): bool
    {
        return $this->chosenPhoto() !== null || $this->chosenImageUrl() !== '' || $this->removeImage;
    }

    /** The file Save stores: one chosen while the computer is the source, and only then. */
    protected function chosenPhoto(): ?TemporaryUploadedFile
    {
        return $this->imageSource === 'upload' && $this->photo instanceof TemporaryUploadedFile ? $this->photo : null;
    }

    /** The link Save imports from: one typed while a link is the source, and only then. */
    protected function chosenImageUrl(): string
    {
        return $this->imageSource === 'link' ? trim($this->imageUrl) : '';
    }

    /**
     * Saves what the draft says about the photo — the chosen file, the linked
     * image, or taking the saved one off — against the plan at $version. A
     * file the site can't use leaves the rest saved and says why by its
     * field, so it can be chosen again; a plan changed or gone meanwhile is
     * the page's to explain.
     *
     * @throws CannotManageStudioPlanException
     */
    protected function saveImage(StudioPlan $plan, int $version): bool
    {
        $admin = $this->admin();
        $photo = $this->chosenPhoto();
        $imageUrl = $this->chosenImageUrl();

        if ($photo !== null) {
            try {
                app(SetStudioPlanImageAction::class)->handle($admin, $plan, $photo, $version);
            } catch (ImageIngestException $exception) {
                report($exception);
                $this->addError('photo', 'This image can’t be used. Choose another JPEG, PNG or WebP image.');

                return false;
            }
        } elseif ($imageUrl !== '') {
            $importer = app(StoreImportedImageAction::class);

            try {
                $file = $importer->download($imageUrl);
            } catch (Throwable $exception) {
                report($exception);
                $this->addError('imageUrl', 'No image could be downloaded from this link. Use a direct link to a JPEG, PNG or WebP file.');

                return false;
            }

            try {
                app(SetStudioPlanImageAction::class)->handle($admin, $plan, $file, $version, ImageInputSource::UrlImport);
            } catch (ImageIngestException $exception) {
                report($exception);
                $this->addError('imageUrl', 'The file at this link isn’t an image the site takes. Use a JPEG, PNG or WebP image.');

                return false;
            } finally {
                $importer->cleanup($file);
            }
        } elseif ($this->removeImage && $plan->image_asset_id !== null) {
            app(RemoveStudioPlanImageAction::class)->handle($admin, $plan, $version);
        }

        $this->photo = null;
        $this->imageUrl = '';
        $this->removeImage = false;

        return true;
    }

    /**
     * What the editor shows besides the draft: the steps, the photo, the
     * Personas, categories, tags and rating groups to choose from, the
     * limits, and the publication card as the draft stands.
     *
     * @return array<string, mixed>
     */
    protected function editorViewData(?MediaAsset $savedImage): array
    {
        $author = $this->authorUserId !== '' ? User::query()->with('avatarAsset.variants')->find((int) $this->authorUserId) : null;
        $category = $this->categoryId !== '' ? Category::query()->find((int) $this->categoryId) : null;
        $photo = $this->photoDetails($savedImage);
        $mode = StudioPlanPublicationMode::tryFrom($this->publicationMode) ?? StudioPlanPublicationMode::Now;

        return [
            'steps' => collect(self::STEPS)->map(fn (array $step, string $key): array => ['key' => $key, 'label' => $step[0], 'caption' => $step[1]])->values()->all(),
            'stepNumber' => array_search($this->step, array_keys(self::STEPS), true) + 1,
            'stepLabel' => self::STEPS[$this->step][0] ?? '',
            'shownPhoto' => $photo,
            'maxImageSize' => Number::fileSize(self::maxImageKilobytes() * 1024),
            'author' => StudioPlanFormat::author($author),
            'authorChoices' => $this->authorChoices(),
            'categories' => $this->categoryOptions($category),
            'tags' => $this->tagChoices(),
            'ratingGroups' => $this->ratingGroups(),
            'limits' => [
                'title' => PostContentRules::TITLE_LIMIT,
                'titleMin' => PostContentRules::TITLE_MIN_LENGTH,
                'description' => PostContentRules::DESCRIPTION_LIMIT,
                'sourceUrl' => PostContentRules::SOURCE_URL_LIMIT,
                'tags' => PostContentRules::MOST_TAGS,
                'earliest' => self::dateInput(CarbonImmutable::parse(StudioPlanValidationRules::EARLIEST_PUBLISH_AT)),
                'latest' => self::dateInput(CarbonImmutable::parse(StudioPlanValidationRules::LATEST_PUBLISH_AT)->startOfMinute()),
            ],
            'modes' => [
                StudioPlanPublicationMode::Now->value => ['label' => 'Publish now', 'help' => 'Published when the plan is approved and runs. Saving the draft publishes nothing.'],
                StudioPlanPublicationMode::AtDate->value => ['label' => 'On a chosen date', 'help' => 'A future or past date the post will carry as its own.'],
            ],
            'timezone' => (string) config('app.timezone'),
            'card' => [
                'image' => $photo['url'],
                'title' => trim($this->postTitle),
                'description' => trim($this->postDescription),
                'author' => StudioPlanFormat::author($author),
                'category' => $category?->translatedName(),
                'when' => StudioPlanFormat::publication($mode, $mode === StudioPlanPublicationMode::AtDate ? self::parsedDate($this->publishAt) : null),
            ],
        ];
    }

    protected function admin(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    protected function toast(string $message, string $tone = 'success'): void
    {
        $this->dispatch('rg-admin-toast', message: $message, tone: $tone);
    }

    /** The date field's value for a date, in the application's time zone; seconds only when it has them. */
    protected static function dateInput(?CarbonImmutable $at): string
    {
        if ($at === null) {
            return '';
        }

        $at = $at->setTimezone((string) config('app.timezone'));

        return $at->format($at->second === 0 ? 'Y-m-d\TH:i' : 'Y-m-d\TH:i:s');
    }

    /** The date field's value as a date in the application's time zone; none for an empty or malformed one. */
    protected static function parsedDate(string $value): ?CarbonImmutable
    {
        foreach (['Y-m-d\TH:i:s', 'Y-m-d\TH:i'] as $format) {
            try {
                $at = CarbonImmutable::createFromFormat('!'.$format, $value, (string) config('app.timezone'));
            } catch (InvalidFormatException) {
                continue;
            }

            if ($at !== null && $at->format($format) === $value) {
                return $at;
            }
        }

        return null;
    }

    /** The step that holds a field, by its key in the error bag. */
    private static function stepOf(string $field): ?string
    {
        return match (true) {
            in_array($field, ['photo', 'imageUrl'], true) => 'image',
            in_array($field, ['publicationMode', 'publishAt'], true) => 'publication',
            $field === '' => null,
            default => 'post',
        };
    }

    private function stepAt(int $offset): string
    {
        $keys = array_keys(self::STEPS);
        $at = array_search($this->step, $keys, true);

        return $keys[max(0, min(count($keys) - 1, ($at === false ? 0 : $at) + $offset))];
    }

    private function dropPhoto(): void
    {
        $this->photo = null;
        $this->resetErrorBag('photo');
    }

    private function dropImageUrl(): void
    {
        $this->imageUrl = '';
        $this->resetErrorBag('imageUrl');
    }

    /** @return list<int> */
    private function chosenTagIds(): array
    {
        return array_values(array_unique(array_map(intval(...), array_filter($this->tagIds, is_numeric(...)))));
    }

    private static function maxImageKilobytes(): int
    {
        return (int) config('uploads.images.max_kilobytes', 5120);
    }

    /** @return array<string, list<mixed>> */
    private function imageRules(): array
    {
        return [
            'photo' => ['nullable', File::image()->types(['jpg', 'jpeg', 'png', 'webp'])->max(self::maxImageKilobytes())],
            'imageUrl' => ['nullable', 'url:http,https', 'max:'.PostContentRules::SOURCE_URL_LIMIT],
        ];
    }

    /** @return array<string, string> */
    private function imageMessages(): array
    {
        return [
            'photo.image' => 'Choose a JPEG, PNG or WebP image.',
            'photo.mimes' => 'Choose a JPEG, PNG or WebP image.',
            'photo.max' => 'Choose an image of '.Number::fileSize(self::maxImageKilobytes() * 1024).' or less.',
            'photo.file' => 'Choose a JPEG, PNG or WebP image.',
            'imageUrl.*' => 'Enter a full web address to an image, starting with https://.',
        ];
    }

    /**
     * The photo as the editor shows it: the one chosen, or the saved one
     * unless Save takes it off, with what it is.
     *
     * @return array{url: ?string, state: string, name: ?string, details: list<string>}
     */
    private function photoDetails(?MediaAsset $saved): array
    {
        $chosen = $this->chosenPhoto();

        if ($chosen !== null && ! $this->getErrorBag()->has('photo')) {
            $size = null;

            try {
                $dimensions = @getimagesize((string) $chosen->getRealPath());
                $size = is_array($dimensions) ? $dimensions[0].' × '.$dimensions[1] : null;
                $url = $chosen->isPreviewable() ? $chosen->temporaryUrl() : null;
            } catch (Throwable) {
                $url = null;
            }

            return [
                'url' => $url,
                'state' => 'chosen',
                'name' => $chosen->getClientOriginalName(),
                'details' => array_values(array_filter([$size, Number::fileSize((int) $chosen->getSize(), precision: 1), $chosen->getMimeType()])),
            ];
        }

        if ($saved !== null && ! $this->removeImage) {
            return [
                'url' => app(PostImagePresenter::class)->assetPreviewUrl($saved),
                'state' => 'saved',
                'name' => $saved->original_filename,
                'details' => array_values(array_filter([
                    $saved->width !== null && $saved->height !== null ? $saved->width.' × '.$saved->height : null,
                    Number::fileSize((int) $saved->byte_size, precision: 1),
                    $saved->mime_type,
                ])),
            ];
        }

        return ['url' => null, 'state' => $saved !== null ? 'removing' : 'none', 'name' => null, 'details' => []];
    }

    /**
     * The Personas offered as the author: those matching the search, at most
     * a handful, never the one already chosen.
     *
     * @return list<array<string, mixed>>
     */
    private function authorChoices(): array
    {
        return app(PersonasQuery::class)
            ->choices(mb_substr(trim($this->authorSearch), 0, 100), self::CHOICES + 1)
            ->reject(fn (User $persona): bool => (string) $persona->id === $this->authorUserId)
            ->take(self::CHOICES)
            ->map(fn (User $persona): ?array => StudioPlanFormat::author($persona))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * The categories offered: the active ones, and the chosen one even when
     * it is no longer active, so nothing is swapped for it unseen.
     *
     * @return list<array{value: string, label: string}>
     */
    private function categoryOptions(?Category $chosen): array
    {
        $options = app(ActiveCategoriesQuery::class)->get()
            ->map(fn (Category $category): array => ['value' => (string) $category->id, 'label' => $category->translatedName()]);

        if ($chosen !== null && ! $chosen->is_active) {
            $options->prepend(['value' => (string) $chosen->id, 'label' => $chosen->translatedName().' · inactive']);
        }

        return [['value' => '', 'label' => 'No category'], ...$options->values()->all()];
    }

    /**
     * The tags chosen, and those offered to add: the ones matching the
     * search, at most a few at a time.
     *
     * @return array{chosen: list<array{id: int, name: string}>, offered: list<array{id: int, name: string}>, full: bool}
     */
    private function tagChoices(): array
    {
        $chosenIds = $this->chosenTagIds();
        $search = mb_strtolower(trim($this->tagSearch));
        /** @var Collection<int, Tag> $tags */
        $tags = Tag::query()->orderBy('name')->get(['id', 'name']);

        return [
            'chosen' => $tags->filter(fn (Tag $tag): bool => in_array($tag->id, $chosenIds, true))
                ->map(fn (Tag $tag): array => ['id' => (int) $tag->id, 'name' => (string) $tag->name])->values()->all(),
            'offered' => $tags->reject(fn (Tag $tag): bool => in_array($tag->id, $chosenIds, true))
                ->filter(fn (Tag $tag): bool => $search === '' || str_contains(mb_strtolower((string) $tag->name), $search))
                ->take(self::CHOICES * 2)
                ->map(fn (Tag $tag): array => ['id' => (int) $tag->id, 'name' => (string) $tag->name])->values()->all(),
            'full' => count($chosenIds) >= PostContentRules::MOST_TAGS,
        ];
    }

    /**
     * The rating groups the author answers in: every active one with its
     * active options, and any group or option an answer was saved for since
     * made inactive, so the answer stays visible and can be taken off.
     *
     * @return list<array{id: int, label: string, inactive: bool, options: list<array{value: string, label: string}>}>
     */
    private function ratingGroups(): array
    {
        $answered = array_map(intval(...), array_filter($this->authorAnswers, fn (mixed $option): bool => is_numeric($option)));
        $groups = app(RatingConfigurationManager::class)->activeGroups()->keyBy('id');
        $saved = RatingOption::query()->whereKey($answered)->with('group')->get();

        foreach ($saved as $option) {
            if (! $groups->has($option->rating_group_id) && $option->group !== null) {
                $groups->put($option->rating_group_id, $option->group->setRelation('options', new EloquentCollection));
            }
        }

        return $groups->values()->map(function (RatingGroup $group) use ($saved): array {
            $options = $group->options->map(fn (RatingOption $option): array => ['value' => (string) $option->id, 'label' => $option->translatedLabel()]);

            foreach ($saved->where('rating_group_id', $group->id) as $option) {
                if (! $options->contains('value', (string) $option->id)) {
                    $options->push(['value' => (string) $option->id, 'label' => $option->translatedLabel().' · inactive']);
                }
            }

            return [
                'id' => (int) $group->id,
                'label' => $group->translatedLabel(),
                'inactive' => ! $group->is_active,
                'options' => [['value' => '', 'label' => 'No answer'], ...$options->values()->all()],
            ];
        })->all();
    }
}
