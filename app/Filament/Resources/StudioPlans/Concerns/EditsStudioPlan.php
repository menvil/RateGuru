<?php

namespace App\Filament\Resources\StudioPlans\Concerns;

use App\Actions\Import\ImportFromUrlAction;
use App\Actions\Import\StoreImportedImageAction;
use App\Actions\Studio\RemoveStudioPlanImageAction;
use App\Actions\Studio\RequestStudioImageAnalysisAction;
use App\Actions\Studio\SetStudioPlanImageAction;
use App\Data\Studio\StudioPlanData;
use App\Enums\ImageInputSource;
use App\Enums\StudioPlanPublicationMode;
use App\Exceptions\Import\UnsafeImportUrlException;
use App\Exceptions\Import\UrlImportDisabledException;
use App\Exceptions\Studio\CannotManageStudioPlanException;
use App\Exceptions\Studio\CannotRequestStudioImageAnalysisException;
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
use App\Support\Import\ImportProviderDetector;
use App\Support\Media\PostImagePresenter;
use App\Support\Posts\PostContentRules;
use App\Support\Rating\RatingConfigurationManager;
use App\Support\Studio\StudioPlanValidationRules;
use App\Support\StudioAi\StudioImageAnalyzer;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\UploadedFile;
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
 * the saved values back. The draft is checked with the rules the Content
 * Studio actions check it with again (StudioPlanValidationRules), and nothing
 * in it can carry a status, a creator or a version.
 *
 * The photo is no part of the draft. One chosen from the computer — picked or
 * dropped — or imported from a link or a social post is stored on the plan
 * the moment it arrives, through SetStudioPlanImageAction against the
 * version the editor last read or wrote (a new publication's first photo
 * makes its Draft first), and its analysis is asked for at once. Only the
 * photo and the version change: the texts on screen stay as typed, and as
 * unsaved as they were, so the next Save is no conflict with the photo.
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

    /** The social sites the importer knows a post of (ImportProviderDetector), by name. */
    private const PLATFORMS = ['instagram' => 'Instagram', 'x' => 'X', 'facebook' => 'Facebook', 'pinterest' => 'Pinterest'];

    /**
     * The step on screen. Not part of the draft, and changed only through
     * goToStep() and the save that shows a step with something to fix, never
     * set from the browser.
     */
    #[Locked]
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
     * A photo chosen from the computer, picked or dropped, as Livewire's
     * upload leaves it: stored the moment it arrives, never kept for Save.
     *
     * @var TemporaryUploadedFile|null
     */
    public $photo = null;

    /** Where a new photo comes from: the computer, a direct link to an image, or a social post. */
    public string $imageSource = 'upload';

    /** A direct link to an image, imported once it is complete. */
    public string $imageUrl = '';

    /** A link to a public post, whose image is imported once the link is complete. */
    public string $postUrl = '';

    /** The plan the photo is kept on: the one edited, or the Draft a new publication made for its first photo; 0 for none yet. */
    #[Locked]
    public int $planId = 0;

    /** The plan's lock_version as the editor last read or wrote it. */
    #[Locked]
    public int $lockVersion = 0;

    /** The source and address an image was last imported from, so the same one is never fetched twice running. */
    #[Locked]
    public string $importedFrom = '';

    /** The upload last stored, so an upload reported twice is stored once. */
    #[Locked]
    public string $keptUpload = '';

    /** The file name of the photo the last one replaced, while the editor stays open. */
    #[Locked]
    public ?string $replacedPhoto = null;

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
     * A photo from the computer is checked as soon as it arrives, then stored
     * and analysed; a wrong file is said by the drop zone and stores nothing.
     */
    public function updatedPhoto(): void
    {
        $this->imageSource = 'upload';
        $this->resetErrorBag(['photo', 'imageUrl', 'postUrl']);

        try {
            $this->validateOnly('photo', $this->imageRules(), $this->imageMessages());
        } catch (ValidationException $exception) {
            $this->photo = null;

            throw $exception;
        }

        $photo = $this->photo;
        $this->photo = null;

        if (! $photo instanceof TemporaryUploadedFile || $photo->getFilename() === $this->keptUpload) {
            return;
        }

        $this->keptUpload = $photo->getFilename();
        $this->keepPhoto('photo', $photo, ImageInputSource::Upload);
    }

    /** Switching the source keeps nothing of the other one's, and no error of it. */
    public function updatedImageSource(): void
    {
        $this->imageSource = in_array($this->imageSource, ['link', 'post'], true) ? $this->imageSource : 'upload';
        $this->resetErrorBag(['photo', 'imageUrl', 'postUrl']);
    }

    /**
     * Imports the image a link points at — a direct link to an image, or the
     * picture of a public post — once the browser finds the address complete,
     * through the importers the site's own uploads use: the safe client for
     * every request, no private address, the temporary file always removed.
     * The same address is never fetched twice running.
     */
    public function importImage(mixed $source): void
    {
        $source = $source === 'post' ? 'post' : 'link';
        $field = $source === 'post' ? 'postUrl' : 'imageUrl';
        $url = trim($source === 'post' ? $this->postUrl : $this->imageUrl);

        $this->imageSource = $source;
        $source === 'post' ? $this->postUrl = $url : $this->imageUrl = $url;
        $this->resetErrorBag(['photo', 'imageUrl', 'postUrl']);

        if ($url === '' || "{$source} {$url}" === $this->importedFrom) {
            return;
        }

        $this->validateOnly($field, $this->imageRules(), $this->imageMessages());
        $this->importedFrom = "{$source} {$url}";

        $imageUrl = $source === 'post' ? $this->postImageUrl($url) : $url;

        if ($imageUrl === null) {
            return;
        }

        $importer = app(StoreImportedImageAction::class);

        try {
            $file = $importer->download($imageUrl);
        } catch (Throwable $exception) {
            report($exception);
            $this->addError($field, $source === 'post'
                ? 'The post’s image couldn’t be downloaded. Try again, or upload the file.'
                : 'No image could be downloaded from this link. Use a direct link to a JPEG, PNG or WebP file.');

            return;
        }

        try {
            $this->keepPhoto($field, $file, ImageInputSource::UrlImport);
        } finally {
            $importer->cleanup($file);
        }
    }

    /** Imports the same address again, after a try that failed. */
    public function retryImport(): void
    {
        if ($this->imageSource === 'upload') {
            return;
        }

        $this->importedFrom = '';
        $this->importImage($this->imageSource);
    }

    /**
     * Takes the photo off the plan at once, released to the media lifecycle
     * — the way a new one replaces it — and its analysis out of view.
     */
    public function removePhoto(): void
    {
        $plan = $this->planId !== 0 ? StudioPlan::query()->find($this->planId) : null;

        if ($plan === null) {
            if ($this->planId !== 0) {
                $this->refusePhoto(CannotManageStudioPlanException::becausePlanIsGone(), 'removed');
            }

            return;
        }

        try {
            app(RemoveStudioPlanImageAction::class)->handle($this->admin(), $plan, $this->lockVersion);
        } catch (CannotManageStudioPlanException $exception) {
            $this->refusePhoto($exception, 'removed');

            return;
        }

        $this->lockVersion = $plan->lock_version;
        $this->forgetPhotoInputs();
        $this->importedFrom = '';
        $this->replacedPhoto = null;
        $this->dispatch('rg-studio-photo-kept');
        $this->toast('Photo removed from this draft', 'info');
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

    /** Puts every value of the draft back as saved. */
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
        $this->authorSearch = '';
        $this->tagSearch = '';
    }

    /**
     * A plan's values as the editor compares them: every value as text, and
     * each list of ids in ascending order.
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
        ];
    }

    /**
     * What a plan's draft is, in one value: whether the texts and settings
     * differ from what was drawn.
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
            ], [
                'authorUserId.*' => 'Choose a persona as the author.',
                'categoryId.*' => 'Choose a category from the list.',
                'tagIds.*' => 'Choose tags from the list.',
                'authorAnswers.*' => 'Choose the author’s answers from the rating options.',
                'publicationMode.*' => 'Choose when to publish.',
                'publishAt.*' => 'Enter a date and time.',
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

    /**
     * The plan a photo is kept on: the page's own, or — on a new publication
     * — the Draft made for its first photo. None when there is none to keep
     * it on, said by refusePhoto().
     */
    abstract protected function photoPlan(): ?StudioPlan;

    /**
     * Says why the photo was not saved or removed: the plan was changed,
     * deleted or closed meanwhile, or the administrator may no longer manage
     * plans. What a refusal means for the page is the page's to show.
     */
    abstract protected function refusePhoto(CannotManageStudioPlanException $exception, string $what = 'saved'): void;

    /**
     * Stores a photo on the plan through the plan's own action, against the
     * version this editor last read or wrote, and asks for its analysis. A
     * file the site can't use is said by the field it came from.
     */
    private function keepPhoto(string $field, UploadedFile $file, ImageInputSource $source): void
    {
        $plan = $this->photoPlan();

        if ($plan === null) {
            return;
        }

        $previous = $plan->image_asset_id !== null ? MediaAsset::query()->find($plan->image_asset_id)?->original_filename : null;

        try {
            app(SetStudioPlanImageAction::class)->handle($this->admin(), $plan, $file, $this->lockVersion, $source);
        } catch (CannotManageStudioPlanException $exception) {
            $this->refusePhoto($exception);

            return;
        } catch (ImageIngestException $exception) {
            report($exception);
            $this->addError($field, $field === 'photo'
                ? 'This image can’t be used. Choose another JPEG, PNG or WebP image.'
                : 'The file at this link isn’t an image the site takes. Use a JPEG, PNG or WebP image.');

            return;
        }

        // The action keeps the copy it was handed in step with the row it
        // wrote: only the photo and the version moved.
        $this->lockVersion = $plan->lock_version;
        $this->forgetPhotoInputs();
        $this->replacedPhoto = $previous;
        $this->dispatch('rg-studio-photo-kept');
        $this->analysePhoto($plan);
    }

    /**
     * Asks for the photo just stored to be analysed — a new attempt for the
     * new photo, so nothing of the one before is shown for it. With analysis
     * not configured, or refused, the photo stays saved and the card says
     * what stands.
     */
    private function analysePhoto(StudioPlan $plan): void
    {
        if ($plan->image_asset_id === null) {
            return;
        }

        try {
            app(RequestStudioImageAnalysisAction::class)->handle($this->admin(), $plan, $plan->image_asset_id);
        } catch (CannotManageStudioPlanException|CannotRequestStudioImageAnalysisException) {
            // Nothing was queued; the card reads the plan as it is.
        } catch (Throwable $exception) {
            // The photo is saved either way, and the card offers the analysis again.
            report($exception);
        }
    }

    /**
     * The image of a public post: what the site's own import finds for the
     * link. None — said by the field — when importing is switched off, the
     * address is not one to fetch, or the post shows no image.
     */
    private function postImageUrl(string $url): ?string
    {
        try {
            $preview = app(ImportFromUrlAction::class)->handle($url);
        } catch (UrlImportDisabledException) {
            $this->addError('postUrl', 'Importing from links is switched off in Project settings.');

            return null;
        } catch (UnsafeImportUrlException) {
            $this->addError('postUrl', 'This address can’t be imported. Use the link of a public post.');

            return null;
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('postUrl', 'This post couldn’t be read. Try again, or upload the file.');

            return null;
        }

        if (! $preview->isSupported() || $preview->imageUrl === null) {
            $this->addError('postUrl', 'No image was found in this post. It may be private, or the site may not share it.');

            return null;
        }

        return $preview->imageUrl;
    }

    private function forgetPhotoInputs(): void
    {
        $this->photo = null;
        $this->imageUrl = '';
        $this->postUrl = '';
        $this->resetErrorBag(['photo', 'imageUrl', 'postUrl']);
    }

    /**
     * What the editor shows besides the draft: the steps, the plan's photo
     * and where a new one comes from, the Personas, categories, tags and
     * rating groups to choose from, the limits, and the publication card as
     * the draft stands.
     *
     * @return array<string, mixed>
     */
    protected function editorViewData(?StudioPlan $plan): array
    {
        $author = $this->authorUserId !== '' ? User::query()->with('avatarAsset.variants')->find((int) $this->authorUserId) : null;
        $category = $this->categoryId !== '' ? Category::query()->find((int) $this->categoryId) : null;
        $photo = $this->photoDetails($plan?->imageAsset);
        $mode = StudioPlanPublicationMode::tryFrom($this->publicationMode) ?? StudioPlanPublicationMode::Now;
        $postProvider = trim($this->postUrl) !== '' ? app(ImportProviderDetector::class)->detect(trim($this->postUrl)) : null;

        return [
            'steps' => collect(self::STEPS)->map(fn (array $step, string $key): array => ['key' => $key, 'label' => $step[0], 'caption' => $step[1]])->values()->all(),
            'stepNumber' => array_search($this->step, array_keys(self::STEPS), true) + 1,
            'stepLabel' => self::STEPS[$this->step][0] ?? '',
            'shownPhoto' => $photo,
            // The card is about the plan the photo is kept on, and drawn afresh for each photo.
            'analysisPlanId' => $this->planId,
            'analysisImageId' => $plan?->image_asset_id,
            'replacedPhoto' => $this->replacedPhoto,
            'analysisConfigured' => app(StudioImageAnalyzer::class)->isConfigured(),
            'platforms' => self::PLATFORMS,
            'detectedPlatform' => self::PLATFORMS[$postProvider] ?? null,
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
                'image' => $photo['url'] ?? null,
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
            in_array($field, ['photo', 'imageUrl', 'postUrl'], true) => 'image',
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
            'postUrl' => ['nullable', 'url:http,https', 'max:'.PostContentRules::SOURCE_URL_LIMIT],
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
            'postUrl.*' => 'Paste the full link of a post, starting with https://.',
        ];
    }

    /**
     * The plan's photo as the editor shows it, with what it is; none while
     * the plan has none.
     *
     * @return array{url: ?string, name: ?string, details: list<string>}|null
     */
    private function photoDetails(?MediaAsset $saved): ?array
    {
        if ($saved === null) {
            return null;
        }

        return [
            'url' => app(PostImagePresenter::class)->assetPreviewUrl($saved),
            'name' => $saved->original_filename,
            'details' => array_values(array_filter([
                $saved->width !== null && $saved->height !== null ? $saved->width.' × '.$saved->height : null,
                Number::fileSize((int) $saved->byte_size, precision: 1),
                $saved->mime_type,
            ])),
        ];
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
