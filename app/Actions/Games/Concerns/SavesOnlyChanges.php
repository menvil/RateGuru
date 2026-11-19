<?php

namespace App\Actions\Games\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * A draft saved again as it was writes nothing: a row a game editor saves
 * keeps its stored blank — an empty text where the editor sends none, or
 * the other way round — so its save time moves only when something players
 * would meet changed (GameUnpublishedChangesQuery reads it).
 */
trait SavesOnlyChanges
{
    /** Whether the row, filled with the draft, is new or changed beyond a blank for a blank. */
    private function keepsChanges(Model $row): bool
    {
        if (! $row->exists) {
            return true;
        }

        foreach (array_keys($row->getDirty()) as $column) {
            $before = $row->getRawOriginal($column);
            $after = $row->getAttributes()[$column] ?? null;

            if (($before === null || $before === '') && ($after === null || $after === '')) {
                $row->setRawAttributes([...$row->getAttributes(), $column => $before]);
            }
        }

        return $row->isDirty();
    }
}
