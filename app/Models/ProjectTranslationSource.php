<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Which English one stored translation of one unit of project content was
 * made from: the unit id and language, the source fingerprint of the unit as
 * the translation was saved (ProjectTranslationSourceFingerprint) and that
 * English text. A translation whose fingerprint here is not the unit's now
 * is outdated; one with no row here is not known to be.
 *
 * Written with every translation by RecordProjectTranslationSourceAction,
 * inside UpdateProjectTranslationAction's transaction, and given to a
 * translation saved some other way by BaselineProjectTranslationSourcesAction.
 * Read by ProjectTranslationSourcesQuery.
 *
 * @property int $id
 * @property string $unit_id
 * @property string $locale
 * @property string $fingerprint
 * @property string $reference
 */
class ProjectTranslationSource extends Model
{
    protected $guarded = [];
}
