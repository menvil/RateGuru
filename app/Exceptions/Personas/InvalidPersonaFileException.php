<?php

namespace App\Exceptions\Personas;

use DomainException;

/**
 * Why a file of Personas to import was refused whole: every problem found in
 * it, each saying where — so one run lists them all, not the first alone.
 */
final class InvalidPersonaFileException extends DomainException
{
    /** @param  list<string>  $problems */
    private function __construct(public readonly array $problems)
    {
        parent::__construct('The Persona file has '.count($problems).' '.(count($problems) === 1 ? 'problem' : 'problems').'.');
    }

    /** @param  list<string>  $problems */
    public static function because(array $problems): self
    {
        return new self($problems);
    }
}
