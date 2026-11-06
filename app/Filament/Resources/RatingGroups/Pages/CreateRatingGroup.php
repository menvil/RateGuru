<?php

namespace App\Filament\Resources\RatingGroups\Pages;

use App\Actions\Rating\CreateRatingGroupAction;
use App\Filament\Resources\Concerns\EditsRatingGroupSettings;
use App\Filament\Resources\RatingGroups\RatingGroupResource;
use App\Models\RatingGroup;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

/**
 * A new rating group, in Admin v2: its key — made from the label until one
 * is typed (FRM-04) and fixed once saved — its English text, its range and
 * its place on post pages. It starts without options, so it starts inactive;
 * Create group in the top bar opens its editor, where its options are made
 * and it is activated.
 */
final class CreateRatingGroup extends Page
{
    use EditsRatingGroupSettings;

    protected static string $resource = RatingGroupResource::class;

    protected string $view = 'filament.pages.rating-group';

    // A screen drawn in Admin v2 brings its own header band and gutter.
    protected Width|string|null $maxContentWidth = 'rg-admin-main';

    protected static ?string $title = 'New rating group';

    public string $draftKey = '';

    /** Whether the key still follows the label, as it does until somebody types one. */
    #[Locked]
    public bool $keyGenerated = true;

    public static function canAccess(array $parameters = []): bool
    {
        return RatingGroupResource::canCreate();
    }

    public function mount(): void
    {
        $this->saved = [
            'key' => '',
            'label' => '',
            'description' => '',
            'min' => RatingGroup::FEWEST_OPTIONS,
            'max' => 4,
            // Last on post pages, after every group there is.
            'sort' => min(self::MAX_SORT_ORDER, (int) RatingGroup::query()->max('sort_order') + 1),
            'active' => false,
        ];

        $this->discard();
    }

    #[On('discard-rating-group')]
    public function discard(): void
    {
        $this->fillDraft($this->saved);
        $this->draftKey = '';
        $this->keyGenerated = true;
        $this->resetErrorBag();
    }

    public function updatedDraftLabel(): void
    {
        if ($this->keyGenerated) {
            $this->draftKey = Str::slug($this->draftLabel, '_');
        }
    }

    public function updatedDraftKey(): void
    {
        $this->keyGenerated = false;
    }

    #[On('save-rating-group')]
    public function save(): void
    {
        // A new group has no options, so it cannot start active.
        $this->draftActive = false;
        $this->draftLabel = Str::squish($this->draftLabel);
        $this->draftKey = trim($this->draftKey);

        $this->validate([
            ...$this->settingsRules(),
            'draftKey' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:_[a-z0-9]+)*$/', Rule::unique(RatingGroup::class, 'key')],
        ], [
            ...$this->settingsMessages(),
            'draftKey.required' => 'Enter a key.',
            'draftKey.max' => 'Keep the key to 120 characters or fewer.',
            'draftKey.regex' => 'Use lowercase letters and numbers, with single underscores between words: real_or_fake.',
            'draftKey.unique' => 'This key is already used by another group. Choose another one.',
        ]);

        $group = app(CreateRatingGroupAction::class)->handle($this->actor(), [
            ...$this->draftData(),
            'key' => $this->draftKey,
        ]);

        session()->flash('rg-admin-toast', "{$group->label} created. Add its options, then activate it.");

        $this->redirect(EditRatingGroup::getUrl(['record' => $group->id]));
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $this->settingsBlocked = $this->rangeProblems(0) !== [];

        return [
            'creating' => true,
            'group' => null,
            'active' => 0,
            'problems' => $this->rangeProblems(0),
            'labelLimit' => self::labelLimit(),
            'descriptionLimit' => self::descriptionLimit(),
        ];
    }
}
