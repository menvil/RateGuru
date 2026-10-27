<?php

namespace App\Actions\Personas;

use App\Data\Personas\PersonaData;
use App\Exceptions\Personas\CannotManagePersonaException;
use App\Models\User;
use App\Support\Observability\DomainLogger;
use App\Support\Personas\PersonaImportPlan;
use App\Support\Personas\PersonaImportReport;
use Illuminate\Validation\ValidationException;

/**
 * Brings the Personas a file describes (PersonaFileReader) into the project,
 * each made by CreatePersonaAction as an administrator making it in the
 * persona editor would — never written any other way — so each is held to
 * the same rules, asked of the same administrator, and made as closed as any
 * Persona: no password, no sign-in identity, an unconfirmed synthetic address
 * and the Persona role.
 *
 * Safe to run again, and to finish a run that stopped half-way. plan() says
 * what each Persona of the file is to the accounts there are now:
 *
 *  - new: no account has its username, so it is created;
 *  - unchanged: a Persona has it with exactly the file's details
 *    (PersonaData::fingerprint()), so it was imported already and is left as
 *    it is;
 *  - a conflict: an account that is not a Persona has it, or a Persona whose
 *    details are not the file's — edited since, or another Persona altogether.
 *
 * Nothing that exists is ever overwritten, updated or deleted: a conflict is
 * the administrator's to settle.
 *
 * handle() creates the new ones in the file's order, each in a transaction of
 * its own, so a run cut short leaves whole Personas behind and the next run
 * finds them unchanged. A username taken since the plan is told apart the
 * same way; an administrator who may no longer manage Personas stops the
 * import where it is.
 */
final class ImportPersonasAction
{
    /** How many usernames are looked for in one query. */
    private const LOOKUP_CHUNK = 500;

    public function __construct(
        private readonly CreatePersonaAction $create,
        private readonly DomainLogger $logger,
    ) {}

    /** @param  list<PersonaData>  $personas  each username once, as PersonaFileReader reads them */
    public function plan(array $personas): PersonaImportPlan
    {
        $accounts = [];

        foreach (array_chunk($personas, self::LOOKUP_CHUNK) as $chunk) {
            User::query()
                ->whereIn('username', array_map(fn (PersonaData $persona): string => (string) $persona->username, $chunk))
                ->with('personaProfile')
                ->get()
                ->each(function (User $account) use (&$accounts): void {
                    $accounts[(string) $account->username] = $account;
                });
        }

        $new = [];
        $unchanged = [];
        $conflicts = [];

        foreach ($personas as $persona) {
            $account = $accounts[(string) $persona->username] ?? null;
            $conflict = $account !== null ? self::conflictWith($account, $persona) : null;

            if ($account === null) {
                $new[] = $persona;
            } elseif ($conflict === null) {
                $unchanged[] = $persona;
            } else {
                $conflicts[(string) $persona->username] = $conflict;
            }
        }

        return new PersonaImportPlan($new, $unchanged, $conflicts);
    }

    /**
     * Creates the plan's new Personas, as this administrator.
     */
    public function handle(User $admin, PersonaImportPlan $plan): PersonaImportReport
    {
        $created = [];
        $unchanged = array_map(fn (PersonaData $persona): string => (string) $persona->username, $plan->unchanged);
        $failed = [];
        $notAttempted = [];
        $stopped = false;

        foreach ($plan->new as $persona) {
            $username = (string) $persona->username;

            if ($stopped) {
                $notAttempted[] = $username;

                continue;
            }

            try {
                $this->create->handle($admin, $persona);
                $created[] = $username;
            } catch (CannotManagePersonaException $exception) {
                if (! $exception->isUsernameTaken()) {
                    $failed[$username] = 'The administrator may no longer manage personas, so the import stopped here.';
                    $stopped = true;

                    continue;
                }

                $this->takenMeanwhile($persona, $unchanged, $failed);
            } catch (ValidationException $exception) {
                $refusal = (string) collect($exception->errors())->flatten()->first();

                // Read from a file, its values passed the same rules; then only
                // whether another account has its username can have changed
                // since. Anything else is said as the rules say it.
                if ($exception->validator->errors()->has('username')) {
                    $this->takenMeanwhile($persona, $unchanged, $failed, $refusal);

                    continue;
                }

                $failed[$username] = $refusal;
            }
        }

        $report = new PersonaImportReport($created, $unchanged, $plan->conflicts, $failed, $notAttempted);

        $this->logger->info('persona.import.finished', [
            'admin_id' => $admin->id,
            'created' => count($report->created),
            'unchanged' => count($report->unchanged),
            'conflicts' => count($report->conflicts),
            'failed' => count($report->failed),
            'not_attempted' => count($report->notAttempted),
        ]);

        return $report;
    }

    /**
     * Its username was refused: taken after the plan was made — by this very
     * Persona, another run of the same file, or by somebody else — or, when no
     * account has it, refused by the rules themselves, as `$refusal` says.
     *
     * @param  list<string>  $unchanged
     * @param  array<string, string>  $failed
     */
    private function takenMeanwhile(PersonaData $persona, array &$unchanged, array &$failed, ?string $refusal = null): void
    {
        $username = (string) $persona->username;
        $account = User::query()->where('username', $username)->with('personaProfile')->first();

        if ($account !== null && self::conflictWith($account, $persona) === null) {
            $unchanged[] = $username;

            return;
        }

        $failed[$username] = $account === null && $refusal !== null
            ? $refusal
            : 'Another account took this username during the import.';
    }

    /** Why the account with a Persona's username is not that Persona as the file has it; none when it is. */
    private static function conflictWith(User $account, PersonaData $persona): ?string
    {
        return match (true) {
            ! $account->isPersona() => 'An account that is not a persona has this username.',
            $account->isTombstoned() => 'A deleted persona still has this username.',
            PersonaData::of($account, $account->personaProfile)->fingerprint() !== $persona->fingerprint() => 'A persona with this username has other details: edited since it was imported, or another persona. Nothing is overwritten.',
            default => null,
        };
    }
}
