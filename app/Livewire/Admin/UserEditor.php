<?php

namespace App\Livewire\Admin;

use App\Actions\Users\UpdateUserAccountAction;
use App\Enums\ModerationActionType;
use App\Enums\SocialProvider;
use App\Enums\UserRole;
use App\Exceptions\Users\CannotUpdateUserAccountException;
use App\Filament\Resources\Personas\Pages\ListPersonas;
use App\Filament\Resources\Personas\PersonaResource;
use App\Filament\Resources\Users\Concerns\ModeratesUsers;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\ModerationListFormat;
use App\Models\Comment;
use App\Models\ModerationLog;
use App\Models\Post;
use App\Models\User;
use App\Queries\Users\ModerationUsersQuery;
use App\Queries\Users\UserModerationProfileQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * A person's editor, in the drawer over Users: their profile, role, trust
 * and password, edited as one draft and saved from the drawer's footer — a
 * change of role asked about first — beside where they stand: their status
 * with the moderation actions that change it, their account, and the
 * moderation of them and of their content. The list opens it (`?user=12`),
 * and closes it once it is saved.
 *
 * Only an administrator edits an account, never their own nor another
 * administrator's (UserPolicy::manage), asked again on every request; the
 * save goes through UpdateUserAccountAction, and every moderation action
 * through its own (ModeratesUsers).
 *
 * A Persona's account is shown and edits none of it: a Persona is made and
 * edited only in Content Studio, where the drawer leads an administrator who
 * may manage Personas, and UpdateUserAccountAction refuses it whatever a
 * request carries. Its status still moves through the same moderation
 * actions as anyone's.
 */
final class UserEditor extends Component
{
    use ModeratesUsers {
        confirmSanction as private applySanction;
        restoreAccess as private restoreAccessNow;
    }

    /** The roles this editor gives. Never Persona: nobody becomes one, or stops being one, here. */
    private const ASSIGNABLE_ROLES = [UserRole::User, UserRole::Moderator, UserRole::Admin];

    /** What each role may do, as the editor says it. */
    private const ROLE_HELP = [
        'user' => 'No admin access.',
        'persona' => 'A managed account. It takes part like a member, never signs in and has no admin access. Its posts wait for moderation.',
        'moderator' => 'Reviews posts, comments and reports.',
        'admin' => 'Full access: people, content, settings and languages.',
    ];

    #[Locked]
    public int $userId = 0;

    public string $name = '';

    public string $username = '';

    public string $email = '';

    public string $role = '';

    /** 'trusted' or 'reviewed'. */
    public string $trust = 'reviewed';

    public string $password = '';

    /** @var array<string, string> what is saved, as the draft holds it */
    #[Locked]
    public array $saved = [];

    /** Whether the role change on screen is being asked about. */
    #[Locked]
    public bool $confirmingRole = false;

    /** Every request asks again whether this account is still the viewer's to edit. */
    public function hydrate(): void
    {
        abort_unless(UserResource::canEdit($this->person()), 403);
    }

    public function mount(int $userId): void
    {
        $person = User::query()->find($userId);

        abort_if($person === null, 404);
        abort_unless(UserResource::canEdit($person), 403);

        $this->userId = $person->id;
        $this->saved = self::savedValuesOf($person);
        $this->fillDraft();
    }

    /** Saves the draft, asking first when it changes the role. */
    public function save(): void
    {
        $person = $this->person();
        abort_unless(UserResource::canEdit($person), 403);

        $this->name = Str::squish($this->name);
        $this->username = trim($this->username);
        $this->email = Str::lower(trim($this->email));

        $this->validate($this->rules($person), [
            'name.required' => 'Enter a name.',
            'username.required' => 'Enter a username.',
            'username.regex' => 'Use lowercase letters, numbers and underscores.',
            'username.unique' => 'This username is taken. Choose another one.',
            'email.required' => 'Enter an email address.',
            'email.email' => 'Enter a valid email address.',
            'email.unique' => 'Another account uses this email address.',
        ]);

        if ($this->role !== $this->saved['role'] && ! $this->confirmingRole) {
            $this->confirmingRole = true;

            return;
        }

        $this->confirmingRole = false;

        try {
            app(UpdateUserAccountAction::class)->handle($this->moderatingAdmin(), $person, [
                'name' => $this->name,
                'username' => $this->username,
                'email' => $this->email,
                'role' => UserRole::from($this->role),
                // Asked only when changed here; the action reads the level on the locked row.
                'trusted' => $this->trust === $this->saved['trust'] ? null : $this->trust === 'trusted',
                'password' => $this->password !== '' ? $this->password : null,
            ]);
        } catch (CannotUpdateUserAccountException $exception) {
            $this->moderationToast($exception->isAboutPersona()
                ? 'A persona’s account can’t be edited here, so nothing was saved.'
                : 'You can’t edit this account any more, so nothing was saved.', tone: 'error');

            return;
        }

        $this->saved = self::savedValuesOf($person->fresh() ?? $person);
        $this->fillDraft();
        $this->dispatch('user-saved', message: 'Changes to '.$this->username.' saved');
    }

    public function closeRoleConfirmation(): void
    {
        $this->confirmingRole = false;
    }

    public function render(): View
    {
        $person = $this->person();
        $profile = app(UserModerationProfileQuery::class);
        $posts = $profile->postsByStatus($person);
        $decision = $profile->statusDecision($person);
        $providers = $profile->providers($person);
        $locale = $person->locale !== null ? config("locales.supported.{$person->locale}.native") : null;

        return view('livewire.admin.user-editor', [
            'person' => [
                'username' => (string) $person->username,
                'name' => (string) $person->name,
                'initials' => ModerationListFormat::initials((string) $person->username),
                'status' => ModerationListFormat::personStatus($person),
                'joined' => $person->created_at?->format('d M Y'),
                'posts' => number_format(array_sum($posts)),
                'comments' => number_format($profile->comments($person)),
                'reports' => number_format(app(ModerationUsersQuery::class)->reportsOnContentOf($person)),
                'since' => $decision === null
                    ? 'Since joining'
                    : 'Since '.ModerationListFormat::when($decision->created_at).($decision->moderator !== null ? ' by '.$decision->moderator->name : ''),
                'reason' => $decision !== null && in_array($decision->action, [ModerationActionType::BanUser, ModerationActionType::LimitUser, ModerationActionType::ShadowbanUser], true) ? $decision->reason : null,
                'signIn' => implode(' · ', array_filter([
                    $person->hasPassword() ? 'Email and password' : null,
                    ...array_map(fn (string $provider): ?string => SocialProvider::tryFrom($provider)?->label(), $providers),
                ])) ?: 'None',
                'language' => is_string($locale) ? $locale : 'Not chosen',
                'postsBreakdown' => implode(' · ', array_filter([
                    $posts['published'] > 0 ? number_format($posts['published']).' published' : null,
                    $posts['pending'] > 0 ? number_format($posts['pending']).' pending' : null,
                    $posts['hidden'] > 0 ? number_format($posts['hidden']).' hidden' : null,
                    $posts['rejected'] > 0 ? number_format($posts['rejected']).' rejected' : null,
                ])) ?: 'None yet',
            ],
            'options' => $this->moderationOptions($person),
            'history' => [
                ...$profile->history($person)->map(fn (ModerationLog $log): array => self::historyItem($log))->all(),
                ['icon' => 'user-plus', 'tone' => 'default', 'title' => 'Account created', 'time' => $person->created_at !== null ? ModerationListFormat::when($person->created_at) : null, 'body' => null],
            ],
            'isPersona' => $person->role === UserRole::Persona,
            // Where a Persona is edited, for whoever may manage Personas.
            'personaEditorUrl' => PersonaResource::canEdit($person) ? ListPersonas::getUrl(['persona' => $person->id]) : null,
            'roleLabel' => (string) $person->role?->label(),
            'roles' => collect(self::ASSIGNABLE_ROLES)->mapWithKeys(fn (UserRole $role): array => [$role->value => $role->label()])->all(),
            'roleHelp' => self::ROLE_HELP,
            // Read from whatever the draft and the saved values hold, so a role
            // the editor does not offer still reads as itself, never a missing key.
            'roleChange' => $this->confirmingRole ? [
                'from' => self::roleLabel($this->saved['role']),
                'to' => self::roleLabel($this->role),
                'help' => self::ROLE_HELP[$this->role] ?? '',
            ] : null,
            'trusts' => [
                'reviewed' => ['label' => 'Not trusted', 'help' => 'Every post waits for moderation.'],
                'trusted' => ['label' => 'Trusted', 'help' => 'Posts publish at once, once their email is confirmed.'],
            ],
            'dialog' => $this->sanctionDialog(),
        ]);
    }

    /** Applies the sanction on screen, or marks the person trusted; the list behind shows it too. */
    public function confirmSanction(): void
    {
        $this->applySanction();
        $this->dispatch('user-changed')->to(ListUsers::class);
    }

    /** Restores access at once; the list behind shows it too. */
    public function restoreAccess(int $id): void
    {
        $this->restoreAccessNow($id);
        $this->dispatch('user-changed')->to(ListUsers::class);
    }

    /** Undone from a toast by the list behind: drawn again as the person is now. */
    #[On('user-changed')]
    public function refreshPerson(): void
    {
        $this->resetErrorBag();
    }

    /** @return array<string, mixed> */
    private function rules(User $person): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // A username kept as it is is not held to today's rules.
            'username' => $this->username === $this->saved['username']
                ? ['required', 'string']
                : ['required', 'string', 'regex:/\A[a-z0-9_]+\z/', 'max:32', Rule::unique(User::class)->ignore($person->id)],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)->ignore($person->id)],
            // A role kept as it is is not held to the roles this editor gives:
            // whether the account may be saved at all is the action's to say.
            'role' => $this->role === $this->saved['role']
                ? ['required', 'string']
                : ['required', Rule::in(array_map(fn (UserRole $role): string => $role->value, self::ASSIGNABLE_ROLES))],
            'trust' => ['required', Rule::in(['trusted', 'reviewed'])],
            'password' => ['nullable', 'string', 'max:255', Password::defaults()],
        ];
    }

    /**
     * One entry of the moderation history (DOM-02).
     *
     * @return array{icon: string, tone: string, title: string, time: ?string, body: ?string}
     */
    private static function historyItem(ModerationLog $log): array
    {
        $by = $log->moderator?->name;
        $subject = match ($log->target_type) {
            Post::class => 'Post #'.$log->target_id,
            Comment::class => 'Comment #'.$log->target_id,
            default => null,
        };

        [$icon, $tone, $title] = match ($log->action) {
            ModerationActionType::BanUser => ['ban', 'danger', 'Banned'],
            ModerationActionType::LimitUser => ['shield-alert', 'default', 'Limited'],
            ModerationActionType::ShadowbanUser => ['eye-off', 'default', 'Shadowbanned'],
            ModerationActionType::RestoreUserAccess, ModerationActionType::UnbanUser => ['rotate-ccw', 'default', 'Access restored'],
            ModerationActionType::MarkUserTrusted => ['shield-check', 'default', 'Marked trusted'],
            ModerationActionType::ApprovePost => ['check', 'default', $subject.' approved'],
            ModerationActionType::RejectPost => ['circle-x', 'danger', $subject.' rejected'],
            ModerationActionType::HidePost, ModerationActionType::HideComment => ['eye-off', 'danger', $subject.' hidden'],
            ModerationActionType::RestorePost, ModerationActionType::RestoreComment => ['eye', 'default', $subject.' restored'],
            ModerationActionType::FinalizePostRemoval, ModerationActionType::FinalizeCommentRemoval => ['lock', 'danger', 'Removal of '.Str::lower((string) $subject).' finalized'],
        };

        $isAboutThePerson = $subject === null;

        return [
            'icon' => $icon,
            'tone' => $tone,
            'title' => $isAboutThePerson && $by !== null ? $title.' by '.$by : $title,
            'time' => $log->created_at !== null ? ModerationListFormat::when($log->created_at) : null,
            'body' => implode(' ', array_filter([
                filled($log->reason) ? 'Reason: '.$log->reason : null,
                ! $isAboutThePerson && $by !== null ? 'By '.$by.'.' : null,
            ])) ?: null,
        ];
    }

    private static function roleLabel(string $role): string
    {
        return UserRole::tryFrom($role)?->label() ?? $role;
    }

    /** @return array<string, string> */
    private static function savedValuesOf(User $person): array
    {
        return [
            'name' => (string) $person->name,
            'username' => (string) $person->username,
            'email' => (string) $person->email,
            'role' => $person->role->value,
            'trust' => ModerationListFormat::trust($person) === 'Trusted' ? 'trusted' : 'reviewed',
            'password' => '',
        ];
    }

    private function fillDraft(): void
    {
        $this->name = $this->saved['name'];
        $this->username = $this->saved['username'];
        $this->email = $this->saved['email'];
        $this->role = $this->saved['role'];
        $this->trust = $this->saved['trust'];
        $this->password = '';
    }

    private function person(): User
    {
        $person = User::query()->find($this->userId);

        abort_if($person === null, 404);

        return $person;
    }
}
