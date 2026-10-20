<?php

namespace App\Filament\Resources\Users\Pages;

use App\Actions\Moderation\RestoreUserAccessAction;
use App\Enums\UserRole;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Filament\Resources\Users\Concerns\ModeratesUsers;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\ModerationListFormat;
use App\Livewire\Admin\UserEditor;
use App\Models\User;
use App\Queries\Users\ModerationUsersQuery;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;

/**
 * Users in Admin v2: everyone with an account, a tab at a time by status or
 * trust, narrowed to a role or to the suspicious and searched on the server,
 * 25 at a time. Status changes only through the moderation actions in each
 * row's menu or in the person's editor.
 *
 * A person is edited in the drawer over the list (UserEditor), never on a
 * page of its own: a row's Edit opens it, and the address says on whom
 * (`?user=12`), so a link or a reload opens it again.
 */
final class ListUsers extends Page
{
    use ModeratesUsers;

    /** The tabs, in order, and their labels. */
    public const TABS = [
        'all' => 'All',
        'active' => 'Active',
        'limited' => 'Limited',
        'banned' => 'Banned',
        'shadowbanned' => 'Shadowbanned',
        'deleted' => 'Deleted',
        'trusted' => 'Trusted',
    ];

    protected static string $resource = UserResource::class;

    protected string $view = 'filament.pages.users';

    // A screen drawn in Admin v2 brings its own header band and gutter.
    protected Width|string|null $maxContentWidth = 'rg-admin-main';

    /** The open tab. Typed loosely: whatever a link puts here reads as a tab. */
    #[Url(except: 'all')]
    public mixed $status = 'all';

    /** The search over the username, the name and the email address. */
    #[Url(except: '')]
    public mixed $q = '';

    /** A role, or every role. */
    #[Url(except: '')]
    public mixed $role = '';

    /** Only the suspicious: shadowbanned, or with reported posts or comments. */
    #[Url(except: false)]
    public mixed $suspicious = false;

    #[Url(as: 'page', except: 1)]
    public mixed $pageNumber = 1;

    /** The person open in the drawer: the id of someone the viewer may edit, or none. */
    #[Url(as: 'user', except: '')]
    public mixed $editing = '';

    public function getTitle(): string
    {
        return 'Users';
    }

    public function mount(): void
    {
        $this->normalize();
    }

    /** A new tab, search or filter starts again from the first page. */
    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'q', 'role', 'suspicious'], true)) {
            $this->pageNumber = 1;
        }

        $this->normalize();
    }

    public function goToPage(mixed $page): void
    {
        $this->pageNumber = max(1, (int) $page);
    }

    public function editUser(mixed $id): void
    {
        $this->editing = is_numeric($id) ? (string) (int) $id : '';
        $this->normalize();
    }

    #[On('close-user')]
    public function closeUser(): void
    {
        $this->editing = '';
    }

    /** Saved in the drawer: it closes, and the list shows the save. */
    #[On('user-saved')]
    public function userSaved(mixed $message = ''): void
    {
        $this->editing = '';
        $this->dispatch('rg-admin-toast', message: (string) $message, tone: 'success');
    }

    /** Moderated in the drawer: the list shows the person as they are now. */
    #[On('user-changed')]
    public function userChanged(): void {}

    /** Undo after a sanction — here, or in the drawer: access is restored. */
    #[On('restore-user-access')]
    public function undoSanction(int $id): void
    {
        $person = User::query()->find($id);

        if ($person !== null && $this->attemptModeration(fn () => app(RestoreUserAccessAction::class)->handle($this->moderatingAdmin(), $person))) {
            $this->moderationToast('Access restored for '.$person->username);
            $this->dispatch('user-changed')->to(UserEditor::class);
        }
    }

    /** Undo after restoring access: the sanction it lifted applies again. */
    #[On('sanction-user-again')]
    public function sanctionAgain(int $id, string $kind): void
    {
        $person = User::query()->find($id);

        if ($person !== null && in_array($kind, ['ban', 'limit', 'shadowban'], true)
            && $this->attemptModeration(fn () => $this->sanctionAction($kind)->handle($this->moderatingAdmin(), $person))) {
            $this->moderationToast($person->username.' is '.['ban' => 'banned', 'limit' => 'limited', 'shadowban' => 'shadowbanned'][$kind].' again');
            $this->dispatch('user-changed')->to(UserEditor::class);
        }
    }

    public function clearFilters(): void
    {
        $this->q = '';
        $this->role = '';
        $this->suspicious = false;
        $this->pageNumber = 1;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $query = app(ModerationUsersQuery::class);
        $counts = $query->counts();
        $paginator = $query->paginate((string) $this->status, (string) $this->q, UserRole::tryFrom((string) $this->role), (bool) $this->suspicious, (int) $this->pageNumber);

        return [
            'tabs' => collect(self::TABS)->map(fn (string $label, string $id): array => [
                'id' => $id,
                'label' => $label,
                'count' => number_format($counts[$id]),
                'href' => UserResource::getUrl('index', $id === 'all' ? [] : ['status' => $id]),
                'attributes' => ['wire:click.prevent' => "\$set('status', '{$id}')"],
            ])->values()->all(),
            'stats' => [
                'total' => number_format($counts['all']),
                'restricted' => number_format($query->restricted()),
                'staff' => number_format($query->staff()),
            ],
            'roles' => [
                ['value' => '', 'label' => 'All roles', 'trigger' => 'All'],
                ...array_map(fn (UserRole $role): array => ['value' => $role->value, 'label' => $role->label()], UserRole::cases()),
            ],
            'roleName' => UserRole::tryFrom((string) $this->role)?->label(),
            'paginator' => $paginator,
            'rows' => array_map(fn (User $person): array => $this->row($person), $paginator->items()),
            'filtered' => trim((string) $this->q) !== '' || $this->role !== '' || (bool) $this->suspicious,
            'dialog' => $this->sanctionDialog(),
        ];
    }

    /**
     * One person as their row shows them.
     *
     * @return array<string, mixed>
     */
    private function row(User $person): array
    {
        $deleted = $person->isTombstoned();
        $options = $this->moderationOptions($person);

        return [
            'id' => (int) $person->id,
            'username' => (string) ($person->username ?? '—'),
            'initials' => ModerationListFormat::initials((string) ($person->username ?? $person->name)),
            'meta' => ($deleted ? 'Deleted account' : (string) $person->name).($person->created_at !== null ? ' · joined '.$person->created_at->format('M Y') : ''),
            'email' => $deleted ? 'Removed on deletion' : (string) $person->email,
            'role' => (string) $person->role?->label(),
            'staff' => $person->role?->isStaff() ?? false,
            'status' => ModerationListFormat::personStatus($person),
            'trust' => ModerationListFormat::trust($person),
            'posts' => (int) $person->posts_count,
            'reports' => (int) $person->getAttribute('reports_on_content'),
            'editUrl' => UserResource::canEdit($person) ? self::getUrl(['user' => $person->id]) : null,
            'postsUrl' => $person->username !== null ? ListPosts::getUrl(['status' => 'all', 'q' => $person->username]) : null,
            'deleted' => $deleted,
            'options' => $options,
        ];
    }

    /** Whatever a link or a forged update put here, read as something the page offers. */
    private function normalize(): void
    {
        $this->status = array_key_exists((string) $this->status, self::TABS) ? (string) $this->status : 'all';
        $this->role = UserRole::tryFrom((string) $this->role) !== null ? (string) $this->role : '';
        $this->suspicious = filter_var($this->suspicious, FILTER_VALIDATE_BOOL);
        $this->q = is_string($this->q) ? mb_substr($this->q, 0, 100) : '';
        $this->pageNumber = max(1, is_numeric($this->pageNumber) ? (int) $this->pageNumber : 1);

        $person = is_numeric($this->editing) ? User::query()->find((int) $this->editing) : null;
        $this->editing = $person !== null && UserResource::canEdit($person) ? (string) $person->id : '';
    }
}
