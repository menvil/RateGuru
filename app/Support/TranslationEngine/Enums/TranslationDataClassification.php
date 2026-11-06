<?php

namespace App\Support\TranslationEngine\Enums;

/**
 * What kind of data a translation batch carries, which decides the providers
 * it may be sent to.
 *
 * It is routing and privacy metadata of RateGuru's, never translation
 * context: the router reads it, each provider's registry entry lists the
 * classifications it accepts, and it is never part of what a model is sent.
 */
enum TranslationDataClassification: string
{
    /** Content the project itself publishes: settings, categories, rating options, static pages. */
    case PublicContent = 'public_content';

    /** Content a visitor wrote and the project shows publicly: posts, comments. */
    case PublicUserGenerated = 'public_user_generated';

    /** Content that is not public. No external provider accepts it unless its registry entry says so. */
    case PrivateContent = 'private_content';
}
