<?php

namespace App\Support\Personas;

/**
 * What an import did (ImportPersonasAction::handle()), by username.
 */
final readonly class PersonaImportReport
{
    /**
     * @param  list<string>  $created  made now
     * @param  list<string>  $unchanged  here already, with exactly the file's details: left as they are
     * @param  array<string, string>  $conflicts  not the file's to create, and left alone: why, by username
     * @param  array<string, string>  $failed  not made: why, by username
     * @param  list<string>  $notAttempted  not tried once the import had to stop
     */
    public function __construct(
        public array $created,
        public array $unchanged,
        public array $conflicts,
        public array $failed,
        public array $notAttempted,
    ) {}

    /** Whether every Persona the import set out to create was created, or found here already. */
    public function finished(): bool
    {
        return $this->failed === [] && $this->notAttempted === [];
    }
}
