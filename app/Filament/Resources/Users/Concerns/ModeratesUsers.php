<?php

namespace App\Filament\Resources\Users\Concerns;

use App\Actions\Moderation\BanUserAction;
use App\Actions\Moderation\LimitUserAction;
use App\Actions\Moderation\MarkUserTrustedAction;
use App\Actions\Moderation\RestoreUserAccessAction;
use App\Actions\Moderation\ShadowbanUserAction;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\Moderation\CannotModerateUserException;
use App\Filament\Support\ModerationListFormat;
use App\Models\User;
use App\Queries\Users\ModerationUsersQuery;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

/**
 * The moderation actions on a person, wherever their screen offers them —
 * the Users list's row menu and the user's own page: Limit, Shadowban, Ban
 * and Mark trusted after a confirmation, Restore access at once. Each goes
 * through its moderation action, which locks both accounts, checks the
 * transition and writes the moderation log.
 *
 * A sanction on an active person toasts with Undo, which restores access;
 * restoring access toasts with Undo, which applies the sanction again.
 * Marking someone trusted has no Undo: nothing takes trust back but an edit.
 */
trait ModeratesUsers
{
    /** How long a moderation reason may be. */
    public const REASON_LIMIT = 500;

    /**
     * The confirmation on screen: what it does and to whom.
     *
     * @var array{kind: 'ban'|'limit'|'shadowban'|'trust', id: int}|null
     */
    #[Locked]
    public ?array $sanction = null;

    public string $reason = '';

    public function askToSanction(string $kind, int $id): void
    {
        $person = User::query()->find($id);

        if ($person !== null && in_array($kind, ['ban', 'limit', 'shadowban', 'trust'], true) && $this->moderationOptions($person)[$kind]['available']) {
            $this->sanction = ['kind' => $kind, 'id' => $id];
            $this->reason = '';
            $this->resetErrorBag();
        }
    }

    public function closeConfirmation(): void
    {
        $this->sanction = null;
        $this->reason = '';
        $this->resetErrorBag();
    }

    /** Applies the sanction on screen, or marks the person trusted. */
    public function confirmSanction(): void
    {
        if ($this->sanction === null) {
            return;
        }

        ['kind' => $kind, 'id' => $id] = $this->sanction;

        $this->validate(
            ['reason' => ['nullable', 'string', 'min:3', 'max:'.self::REASON_LIMIT]],
            [
                'reason.min' => 'Say a little more: at least 3 characters.',
                'reason.max' => 'Keep it to '.number_format(self::REASON_LIMIT).' characters or fewer.',
            ],
        );

        $reason = trim($this->reason) !== '' ? trim($this->reason) : null;
        $person = User::query()->find($id);
        $this->closeConfirmation();

        if ($person === null) {
            return;
        }

        $wasActive = $person->status === UserStatus::Active;

        if (! $this->attemptModeration(fn () => $this->sanctionAction($kind)->handle($this->moderatingAdmin(), $person, $reason))) {
            return;
        }

        if ($kind === 'trust') {
            $this->moderationToast($person->username.' is now trusted');

            return;
        }

        $this->moderationToast(
            $person->username.' is now '.['ban' => 'banned', 'limit' => 'limited', 'shadowban' => 'shadowbanned'][$kind],
            $wasActive ? ['label' => 'Undo', 'event' => 'restore-user-access', 'params' => ['id' => $id]] : null,
        );
    }

    /** Restores access at once; Undo applies the sanction again. */
    public function restoreAccess(int $id): void
    {
        $person = User::query()->find($id);
        $previous = match ($person?->status) {
            UserStatus::Banned => 'ban',
            UserStatus::Limited => 'limit',
            UserStatus::Shadowbanned => 'shadowban',
            default => null,
        };

        if ($person === null || $previous === null || ! $this->attemptModeration(fn () => app(RestoreUserAccessAction::class)->handle($this->moderatingAdmin(), $person))) {
            return;
        }

        $this->moderationToast('Access restored for '.$person->username, ['label' => 'Undo', 'event' => 'sanction-user-again', 'params' => ['id' => $id, 'kind' => $previous]]);
    }

    /** Undo after a sanction: access is restored. */
    #[On('restore-user-access')]
    public function undoSanction(int $id): void
    {
        $person = User::query()->find($id);

        if ($person !== null && $this->attemptModeration(fn () => app(RestoreUserAccessAction::class)->handle($this->moderatingAdmin(), $person))) {
            $this->moderationToast('Access restored for '.$person->username);
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
        }
    }

    /**
     * What this moderator may do to this person now, each with why not when
     * it is not on offer.
     *
     * @return array{restore: bool, trust: array{available: bool, hint: string}, limit: array{available: bool, hint: string, shown: bool}, shadowban: array{available: bool, hint: string, shown: bool}, ban: array{available: bool, hint: string, shown: bool}}
     */
    protected function moderationOptions(User $person): array
    {
        $admin = $this->moderatingAdmin();
        $status = $person->status;
        $blocked = match (true) {
            $person->isTombstoned() => 'The account was deleted',
            $person->id === $admin->id => 'This is your account',
            $person->role === UserRole::Admin => 'Administrators can’t be sanctioned',
            ! $admin->can('ban', $person) => 'Administrators only',
            default => null,
        };
        $restricted = in_array($status, [UserStatus::Limited, UserStatus::Banned, UserStatus::Shadowbanned], true);

        return [
            'restore' => $restricted && $admin->can('restoreAccess', $person),
            'trust' => match (true) {
                ModerationListFormat::trust($person) === 'Trusted' => ['available' => false, 'hint' => 'Already trusted'],
                $person->role !== UserRole::User => ['available' => false, 'hint' => 'Only members are marked trusted'],
                $status !== UserStatus::Active => ['available' => false, 'hint' => 'Restore access first'],
                ! $admin->can('markTrusted', $person) => ['available' => false, 'hint' => $blocked ?? 'Administrators only'],
                default => ['available' => true, 'hint' => 'Posts skip the queue'],
            },
            'limit' => ['available' => $blocked === null && $status === UserStatus::Active, 'hint' => $blocked ?? 'No posts, comments or votes', 'shown' => $status === UserStatus::Active],
            'shadowban' => ['available' => $blocked === null && in_array($status, [UserStatus::Active, UserStatus::Limited], true), 'hint' => $blocked ?? 'Listed among suspicious users', 'shown' => in_array($status, [UserStatus::Active, UserStatus::Limited], true)],
            'ban' => ['available' => $blocked === null && ! in_array($status, [UserStatus::Banned, UserStatus::Deleted], true), 'hint' => $blocked ?? 'Stops all participation', 'shown' => ! in_array($status, [UserStatus::Banned, UserStatus::Deleted], true)],
        ];
    }

    /**
     * The confirmation on screen, with the person it is about as they are now.
     *
     * @return array{kind: string, username: string, name: string, meta: string, posts: int, reports: int}|null
     */
    protected function sanctionDialog(): ?array
    {
        $person = $this->sanction !== null ? User::query()->withCount('posts')->find($this->sanction['id']) : null;

        if ($person === null) {
            return null;
        }

        $reports = app(ModerationUsersQuery::class)->reportsOnContentOf($person);
        $posts = (int) $person->posts_count;

        return [
            'kind' => $this->sanction['kind'],
            'username' => (string) $person->username,
            'name' => (string) $person->name,
            'meta' => ModerationListFormat::personStatus($person)['label'].' · '.ModerationListFormat::trust($person).' · '.number_format($posts).' '.($posts === 1 ? 'post' : 'posts').' · '.number_format($reports).' '.($reports === 1 ? 'report' : 'reports'),
            'posts' => $posts,
            'reports' => $reports,
        ];
    }

    /** @return BanUserAction|LimitUserAction|ShadowbanUserAction|MarkUserTrustedAction */
    private function sanctionAction(string $kind): object
    {
        return match ($kind) {
            'ban' => app(BanUserAction::class),
            'limit' => app(LimitUserAction::class),
            'shadowban' => app(ShadowbanUserAction::class),
            default => app(MarkUserTrustedAction::class),
        };
    }

    /**
     * Runs one moderation action, and says in a toast why it did not happen:
     * the person changed meanwhile, or this moderator may not.
     */
    private function attemptModeration(callable $action): bool
    {
        try {
            $action();

            return true;
        } catch (CannotModerateUserException) {
            $this->moderationToast('This account changed meanwhile, so nothing was done. The page now shows it as it is.', tone: 'error');

            return false;
        }
    }

    /** @param array{label: string, event: string, params: array<string, mixed>}|null $action */
    private function moderationToast(string $message, ?array $action = null, string $tone = 'success'): void
    {
        $this->dispatch('rg-admin-toast', message: $message, tone: $tone, action: $action);
    }

    private function moderatingAdmin(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
