<?php

namespace App\Filament\Resources\Concerns;

use App\Filament\Support\ContentTranslationStatus;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/**
 * What the Categories and Tags screens share: one record at a time in a
 * drawer (OVL-02), created in English or edited there, with a slug that is
 * generated from the name until somebody types one (FRM-04), and locked
 * once saved until somebody unlocks it on purpose.
 *
 * The drawer's state is the server's: which record it edits, whether it is
 * creating one, and whether the slug is generated or unlocked are locked
 * against the browser, and every method finds its record again. The record
 * the drawer edits is in the address (?edit=4), so global search, an Edit
 * source link in Translation Center or a reload opens it.
 */
trait EditsTaxonomyInDrawer
{
    /**
     * The record the drawer edits. Typed loosely on purpose: whatever a link
     * puts here is read back as a record or as nothing, never as an error.
     */
    #[Url(as: 'edit')]
    #[Locked]
    public mixed $editing = null;

    #[Locked]
    public bool $creating = false;

    public string $draftName = '';

    public string $draftSlug = '';

    /** Whether the slug still follows the name, as it does on create until somebody types one. */
    #[Locked]
    public bool $slugGenerated = false;

    /** Whether a saved record's slug may change: only after Change slug. */
    #[Locked]
    public bool $slugUnlocked = false;

    /** The record the delete confirmation on screen is about. */
    #[Locked]
    public ?int $deleting = null;

    public function updatedDraftName(): void
    {
        if ($this->creating && $this->slugGenerated) {
            $this->draftSlug = Str::slug($this->draftName);
        }
    }

    public function updatedDraftSlug(): void
    {
        $this->slugGenerated = false;
    }

    public function unlockSlug(): void
    {
        if ($this->editing !== null) {
            $this->slugUnlocked = true;
        }
    }

    public function closeDrawer(): void
    {
        $this->editing = null;
        $this->creating = false;
        $this->slugGenerated = false;
        $this->slugUnlocked = false;
        $this->draftName = '';
        $this->draftSlug = '';
        $this->resetErrorBag();
    }

    public function closeConfirmation(): void
    {
        $this->deleting = null;
    }

    /** A record id from the browser or the address, or null for anything that is not one. */
    protected static function recordId(mixed $id): ?int
    {
        if (is_int($id)) {
            return $id > 0 ? $id : null;
        }

        return is_string($id) && ctype_digit($id) && $id[0] !== '0' ? (int) $id : null;
    }

    protected function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * @param  array{label: string, event: string, params: array<string, mixed>}|null  $action
     */
    protected function toast(string $message, string $tone = 'success', ?array $action = null): void
    {
        $this->dispatch('rg-admin-toast', message: $message, tone: $tone, action: $action);
    }

    /** The created toast: what was created, and how many offered languages it is not in yet. */
    protected function createdMessage(string $name): string
    {
        $languages = count(app(ContentTranslationStatus::class)->languages());

        return $languages === 0
            ? "{$name} created"
            : "{$name} created. Translations are missing for {$languages} ".Str::plural('language', $languages).'.';
    }
}
