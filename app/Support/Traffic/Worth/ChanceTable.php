<?php

namespace App\Support\Traffic\Worth;

/**
 * The chances of the last working-out for the slices asked about: a step's
 * chance in a slice, or in the nearest slice above it that has one, and
 * whether that slice stands on its own accounts (30 or more) or leans on
 * the one above. A slice with no row of its own is thin; with no working-out
 * at all, every chance is 0.
 */
final readonly class ChanceTable
{
    /** @param  array<string, array<string, array{chance: string, thin: bool}>>  $rows  by slice key, then step */
    public function __construct(public array $rows = []) {}

    /** @return array{chance: float, thin: bool} */
    public function of(Slice $slice, string $step): array
    {
        $own = true;

        foreach ($slice->chain() as $candidate) {
            $row = $this->rows[$candidate->key()][$step] ?? null;

            if ($row !== null) {
                return ['chance' => (float) $row['chance'], 'thin' => $row['thin'] || ! $own];
            }

            $own = false;
        }

        return ['chance' => 0.0, 'thin' => true];
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }
}
