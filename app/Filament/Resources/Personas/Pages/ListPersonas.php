<?php

namespace App\Filament\Resources\Personas\Pages;

use App\Enums\PersonaEmojiFrequency;
use App\Enums\PersonaEngagement;
use App\Enums\UserStatus;
use App\Filament\Resources\Personas\PersonaResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\ModerationListFormat;
use App\Models\User;
use App\Queries\Personas\PersonasQuery;
use App\Support\Media\AvatarUrlResolver;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Livewire\Attributes\Url;

/**
 * Content Studio's Personas in Admin v2: every living Persona, searched and
 * narrowed by status and engagement on the server, 25 at a time, each with
 * its editor, its public profile and its moderation in Users. Status changes
 * there, through the moderation actions; this list only shows it.
 */
final class ListPersonas extends Page
{
    protected static string $resource = PersonaResource::class;

    protected string $view = 'filament.pages.personas';

    // A screen drawn in Admin v2 brings its own header band and gutter.
    protected Width|string|null $maxContentWidth = 'rg-admin-main';

    /** The search over the username, the names, the short description and the character. */
    #[Url(except: '')]
    public mixed $q = '';

    /** A living status, or every one. Typed loosely: whatever a link puts here reads as a filter. */
    #[Url(except: '')]
    public mixed $status = '';

    /** An engagement level, or every one. */
    #[Url(except: '')]
    public mixed $engagement = '';

    #[Url(as: 'page', except: 1)]
    public mixed $pageNumber = 1;

    public static function canAccess(array $parameters = []): bool
    {
        return PersonaResource::canAccess();
    }

    public function getTitle(): string
    {
        return 'Personas';
    }

    public function mount(): void
    {
        $this->normalize();
    }

    /** A new search or filter starts again from the first page. */
    public function updated(string $property): void
    {
        if (in_array($property, ['q', 'status', 'engagement'], true)) {
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
        $this->status = '';
        $this->engagement = '';
        $this->pageNumber = 1;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $query = app(PersonasQuery::class);
        $stats = $query->stats();
        $status = UserStatus::tryFrom((string) $this->status);
        $engagement = PersonaEngagement::tryFrom((string) $this->engagement);
        $paginator = $query->paginate((string) $this->q, $status, $engagement, (int) $this->pageNumber);

        // A page past the end — an old link, or personas gone since — shows the last one instead.
        if ($paginator->isEmpty() && $paginator->currentPage() > 1 && $paginator->total() > 0) {
            $this->pageNumber = $paginator->lastPage();
            $paginator = $query->paginate((string) $this->q, $status, $engagement, $this->pageNumber);
        }

        return [
            'stats' => array_map(fn (int $count): string => number_format($count), $stats),
            'statuses' => [
                ['value' => '', 'label' => 'All statuses', 'trigger' => 'All'],
                ...array_map(fn (UserStatus $status): array => ['value' => $status->value, 'label' => ModerationListFormat::statusBadge($status)['label']], PersonasQuery::STATUSES),
            ],
            'engagements' => [
                ['value' => '', 'label' => 'All levels', 'trigger' => 'All'],
                ...array_map(fn (PersonaEngagement $level): array => ['value' => $level->value, 'label' => $level->label()], PersonaEngagement::cases()),
            ],
            'statusName' => $status !== null ? ModerationListFormat::statusBadge($status)['label'] : null,
            'engagementName' => $engagement?->label(),
            'paginator' => $paginator,
            'rows' => array_map(fn (User $persona): array => $this->row($persona), $paginator->items()),
            'filtered' => trim((string) $this->q) !== '' || $status !== null || $engagement !== null,
            'createUrl' => CreatePersona::getUrl(),
            'empty' => (int) $stats['total'] === 0,
        ];
    }

    /**
     * One Persona as its row shows it.
     *
     * @return array<string, mixed>
     */
    private function row(User $persona): array
    {
        $profile = $persona->personaProfile;
        $name = (string) ($persona->display_name ?: $persona->name);

        return [
            'id' => (int) $persona->id,
            'name' => $name,
            'username' => (string) $persona->username,
            'initials' => ModerationListFormat::initials($name !== '' ? $name : (string) $persona->username),
            'avatar' => app(AvatarUrlResolver::class)->url($persona),
            'short' => (string) $profile?->short_description,
            'style' => (string) $profile?->speech_style,
            'emoji' => ($profile->emoji_frequency ?? PersonaEmojiFrequency::None)->label(),
            'status' => ModerationListFormat::personStatus($persona),
            'engagement' => ($profile->engagement ?? PersonaEngagement::Off)->label(),
            'replies' => (int) ($profile->max_replies_per_day ?? 0),
            'editUrl' => EditPersona::getUrl(['record' => $persona]),
            'profileUrl' => route('profile.show', ['username' => $persona->username]),
            'usersUrl' => UserResource::canEdit($persona) ? UserResource::getUrl('edit', ['record' => $persona]) : null,
        ];
    }

    /** Whatever a link or a forged update put here, read as something the page offers. */
    private function normalize(): void
    {
        $status = is_string($this->status) ? UserStatus::tryFrom($this->status) : null;
        $engagement = is_string($this->engagement) ? PersonaEngagement::tryFrom($this->engagement) : null;

        $this->status = $status !== null && in_array($status, PersonasQuery::STATUSES, true) ? $status->value : '';
        $this->engagement = $engagement !== null ? $engagement->value : '';
        $this->q = is_string($this->q) ? mb_substr($this->q, 0, 100) : '';
        $this->pageNumber = max(1, is_numeric($this->pageNumber) ? (int) $this->pageNumber : 1);
    }
}
