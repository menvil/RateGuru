<?php

namespace App\Actions\Personas;

use App\Actions\Auth\Concerns\CreatesUserWithUniqueUsername;
use App\Actions\Users\GenerateUniqueUsernameAction;
use App\Data\Personas\PersonaData;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\Auth\CannotGenerateUsernameException;
use App\Exceptions\Personas\CannotManagePersonaException;
use App\Models\Concerns\LocksActorForWrite;
use App\Models\PersonaProfile;
use App\Models\User;
use App\Support\Database\UsernameUniqueViolation;
use App\Support\Observability\DomainLogger;
use App\Support\Personas\PersonaValidationRules;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * An administrator makes a Persona: an ordinary `users` row with the Persona
 * role and its Content Studio profile, created together or not at all.
 *
 * Everything that could let anyone in is the server's to set and set closed:
 * no password, no remember token, no sign-in identity, and an unconfirmed
 * synthetic address — `persona-{uuid}@persona.invalid`, on the reserved
 * domain, unique like any address and guessable from nothing. The account is
 * created Active, the one status a new account has; trust is the default of
 * any new account. Nothing announces it: no Registered event, no mail, no
 * session.
 *
 * The username is the administrator's if they give one — held to the rules
 * every account keeps, and refused when another account has it — or made from
 * the display name otherwise, retried past a race for the same handle as
 * every registration path does (CreatesUserWithUniqueUsername).
 *
 * Only an active administrator may (`manage-personas`), asked again on their
 * locked row inside the transaction.
 */
final class CreatePersonaAction
{
    use CreatesUserWithUniqueUsername;
    use LocksActorForWrite;

    /** The reserved, never-routable domain every Persona's address is on. */
    public const EMAIL_DOMAIN = 'persona.invalid';

    public function __construct(
        private readonly GenerateUniqueUsernameAction $generateUniqueUsername,
        private readonly PersonaValidationRules $rules,
        private readonly DomainLogger $logger,
    ) {}

    /**
     * @throws CannotManagePersonaException when the administrator may not, or the username given is taken
     * @throws CannotGenerateUsernameException when no username could be made from the display name
     * @throws ValidationException keyed as the editor's fields
     */
    public function handle(User $admin, PersonaData $data): User
    {
        if (! $admin->can('manage-personas')) {
            throw CannotManagePersonaException::becauseUserIsNotAllowed();
        }

        $data = $this->rules->validated($data, null);

        if ($data->username === null) {
            $persona = $this->createUserWithUniqueUsername(
                $this->generateUniqueUsername,
                $data->displayName,
                fn (string $username): User => $this->create($admin, $data, $username),
            );
        } else {
            $persona = $this->createWithUsername($admin, $data, $data->username);
        }

        $this->logger->info('persona.created', [
            'user_id' => $persona->id,
            'admin_id' => $admin->id,
        ]);

        return $persona;
    }

    /** The administrator's own username: taken is an answer, never a retry under another name. */
    private function createWithUsername(User $admin, PersonaData $data, string $username): User
    {
        try {
            return DB::transaction(fn (): User => $this->create($admin, $data, $username, checkUsername: true));
        } catch (QueryException $exception) {
            if (UsernameUniqueViolation::matches($exception)) {
                throw CannotManagePersonaException::becauseUsernameIsTaken();
            }

            throw $exception;
        }
    }

    /** Runs inside the transaction: the account and its profile land together or not at all. */
    private function create(User $admin, PersonaData $data, string $username, bool $checkUsername = false): User
    {
        $lockedAdmin = $this->lockActor($admin);

        if ($lockedAdmin === null || ! $lockedAdmin->can('manage-personas')) {
            throw CannotManagePersonaException::becauseUserIsNotAllowed();
        }

        if ($checkUsername && User::query()->where('username', $username)->exists()) {
            throw CannotManagePersonaException::becauseUsernameIsTaken();
        }

        $persona = new User;
        $persona->forceFill([
            ...$data->accountAttributes(),
            'username' => $username,
            'email' => self::syntheticEmail(),
            'email_verified_at' => null,
            'password' => null,
            'remember_token' => null,
            'role' => UserRole::Persona,
            'status' => UserStatus::Active,
        ])->save();

        $profile = new PersonaProfile;
        $profile->forceFill([
            ...$data->profileAttributes(),
            'user_id' => $persona->id,
            'created_by_user_id' => $lockedAdmin->id,
        ])->save();

        return $persona->setRelation('personaProfile', $profile);
    }

    private static function syntheticEmail(): string
    {
        return 'persona-'.Str::uuid()->toString().'@'.self::EMAIL_DOMAIN;
    }
}
