<?php

namespace App\Actions\Personas;

use App\Data\Personas\PersonaData;
use App\Exceptions\Personas\CannotManagePersonaException;
use App\Models\Concerns\LocksUsersInOrder;
use App\Models\PersonaProfile;
use App\Models\User;
use App\Support\Database\UsernameUniqueViolation;
use App\Support\Observability\DomainLogger;
use App\Support\Personas\PersonaValidationRules;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An administrator's edit of a Persona in Content Studio: its display name,
 * username and bio, and its profile — never its role, status, trust,
 * address, password, sign-in identities or sessions, which PersonaData has
 * no room for. Its status moves only through the moderation actions; a
 * Limited, Banned or Shadowbanned Persona can still have its settings
 * corrected here, and stays as sanctioned as it was.
 *
 * The administrator's row and the Persona's are locked in ascending id order
 * (LocksUsersInOrder) — the order a sanction of the Persona by the same
 * administrator takes, so the two cannot deadlock — then its profile, and
 * everything is asked again on them: the administrator may still manage
 * Personas, the account is still a Persona and not deleted, the username is
 * still free — and the Persona is still what the editor was drawn from
 * (`$expectedFingerprint`), so a draft made before somebody else's save is
 * refused rather than written over it. Both rows are written in the one
 * transaction: the account and its profile change together or not at all.
 *
 * A Persona made before it had a profile is given one by its first save.
 */
final class UpdatePersonaAction
{
    use LocksUsersInOrder;

    public function __construct(
        private readonly PersonaValidationRules $rules,
        private readonly DomainLogger $logger,
    ) {}

    /**
     * @param  string  $expectedFingerprint  PersonaData::fingerprint() of the Persona as the editor was drawn from it
     *
     * @throws CannotManagePersonaException when the administrator may not, the Persona is gone or changed meanwhile, or the username is taken
     * @throws ValidationException keyed as the editor's fields
     */
    public function handle(User $admin, User $persona, PersonaData $changes, string $expectedFingerprint): void
    {
        if (! $admin->can('manage-personas')) {
            throw CannotManagePersonaException::becauseUserIsNotAllowed();
        }

        $changes = $this->rules->validated($changes, $persona);

        try {
            DB::transaction(function () use ($admin, $persona, $changes, $expectedFingerprint): void {
                $rows = $this->lockUsersInOrder((int) $admin->getKey(), (int) $persona->getKey());
                $lockedAdmin = $rows->get($admin->getKey());

                if ($lockedAdmin === null || ! $lockedAdmin->can('manage-personas')) {
                    throw CannotManagePersonaException::becauseUserIsNotAllowed();
                }

                $locked = $rows->get($persona->getKey());

                // The role and the status that count are the ones the row has
                // now, whatever the editor was drawn from.
                if ($locked === null || ! $locked->isPersona() || $locked->isTombstoned()) {
                    throw CannotManagePersonaException::becausePersonaIsGone();
                }

                $profile = PersonaProfile::query()->where('user_id', $locked->id)->lockForUpdate()->first()
                    ?? (new PersonaProfile)->forceFill(['user_id' => $locked->id]);

                if (PersonaData::of($locked, $profile)->fingerprint() !== $expectedFingerprint) {
                    throw CannotManagePersonaException::becausePersonaChanged();
                }

                if ($changes->username !== $locked->username
                    && User::query()->where('username', $changes->username)->exists()) {
                    throw CannotManagePersonaException::becauseUsernameIsTaken();
                }

                $locked->forceFill([
                    ...$changes->accountAttributes(),
                    'username' => $changes->username,
                ])->save();

                $profile->forceFill($changes->profileAttributes())->save();

                $persona->setRawAttributes($locked->getAttributes(), true);
                $persona->setRelation('personaProfile', $profile);
            });
        } catch (QueryException $exception) {
            if (UsernameUniqueViolation::matches($exception)) {
                throw CannotManagePersonaException::becauseUsernameIsTaken();
            }

            throw $exception;
        }

        $this->logger->info('persona.updated', [
            'user_id' => $persona->id,
            'admin_id' => $admin->id,
        ]);
    }
}
