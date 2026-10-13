<?php

namespace App\Filament\Resources\Users\Pages;

use App\Actions\Moderation\MarkUserTrustedAction;
use App\Actions\Users\UpdateUserAccountAction;
use App\Enums\ModerationActionType;
use App\Enums\SocialProvider;
use App\Enums\UserRole;
use App\Exceptions\Users\CannotUpdateUserAccountException;
use App\Filament\Resources\Users\Concerns\ModeratesUsers;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\ModerationListFormat;
use App\Models\Comment;
use App\Models\ModerationLog;
use App\Models\Post;
use App\Models\User;
use App\Queries\Users\ModerationUsersQuery;
use App\Queries\Users\UserModerationProfileQuery;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

/**
 * A person's own page in Admin v2: their profile, role, trust and password,
 * edited as one draft and saved from the top bar (NAV-02) — a change of role
 * asked about first — beside where they stand: their status, which only the
 * moderation actions change, their account, and the moderation of them and
 * of their content.
 *
 * Only an administrator edits an account, never their own nor another
 * administrator's (UserPolicy::manage); the save goes through
 * UpdateUserAccountAction.
 */
final class EditUser extends Page
{
    use ModeratesUsers;

    protected static string $resource = UserResource::class;

    protected string $view = 'filament.pages.user';

    // A screen drawn in Admin v2 brings its own header band and gutter.
    protected Width|string|null $maxContentWidth = 'rg-admin-main';

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

    public function mount(int|string $record): void
    {
        $person = is_numeric($record) ? User::query()->find((int) $record) : null;

        abort_if($person === null, 404);
        abort_unless(UserResource::canEdit($person), 403);

        $this->userId = $person->id;
        $this->saved = self::savedValuesOf($person);
        $this->fillDraft();
    }

    public function getTitle(): string
    {
        return (string) $this->person()->username;
    }

    #[On('discard-user')]
    public function discard(): void
    {
        $this->fillDraft();
        $this->resetErrorBag();
    }

    /** Saves the draft, asking first when it changes the role. */
    #[On('save-user')]
    public function save(): void
    {
        $person = $this->person();
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
                // Trust moves only across the trusted level: a level that already reads the same is kept.
                'trust_level' => match (true) {
                    $this->trust === $this->saved['trust'] => (int) $person->trust_level,
                    $this->trust === 'trusted' => MarkUserTrustedAction::TRUSTED_LEVEL,
                    default => 0,
                },
                'password' => $this->password !== '' ? $this->password : null,
            ]);
        } catch (CannotUpdateUserAccountException) {
            $this->moderationToast('You can’t edit this account any more, so nothing was saved.', tone: 'error');

            return;
        }

        $this->saved = self::savedValuesOf($person->fresh() ?? $person);
        $this->fillDraft();
        $this->moderationToast('Changes to '.$this->username.' saved');
    }

    public function closeRoleConfirmation(): void
    {
        $this->confirmingRole = false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $person = $this->person();
        $profile = app(UserModerationProfileQuery::class);
        $posts = $profile->postsByStatus($person);
        $decision = $profile->statusDecision($person);
        $providers = $profile->providers($person);
        $locale = $person->locale !== null ? config("locales.supported.{$person->locale}.native") : null;

        return [
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
            'roles' => ['user' => 'User', 'moderator' => 'Moderator', 'admin' => 'Admin'],
            'roleHelp' => [
                'user' => 'No admin access.',
                'moderator' => 'Reviews posts, comments and reports.',
                'admin' => 'Full access: people, content, settings and languages.',
            ],
            'trusts' => [
                'reviewed' => ['label' => 'Not trusted', 'help' => 'Every post waits for moderation.'],
                'trusted' => ['label' => 'Trusted', 'help' => 'Posts publish at once, once their email is confirmed.'],
            ],
            'dialog' => $this->sanctionDialog(),
        ];
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
            'role' => ['required', Rule::enum(UserRole::class)],
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
