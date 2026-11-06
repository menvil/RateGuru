<?php

namespace App\Support\Personas;

use App\Data\Personas\PersonaData;

/**
 * What an import of a file's Personas would do with the accounts there are
 * now (ImportPersonasAction::plan()): create the new ones, leave the ones
 * already here as they are, and not touch the ones in conflict.
 */
final readonly class PersonaImportPlan
{
    /**
     * @param  list<PersonaData>  $new  no account has their usernames: each is to be created
     * @param  list<PersonaData>  $unchanged  a Persona has each username, with exactly these details
     * @param  array<string, string>  $conflicts  why each is not the file's to create, by username
     */
    public function __construct(
        public array $new,
        public array $unchanged,
        public array $conflicts,
    ) {}

    /** How many Personas the file holds. */
    public function count(): int
    {
        return count($this->new) + count($this->unchanged) + count($this->conflicts);
    }
}
