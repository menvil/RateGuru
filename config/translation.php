<?php

use App\Support\TranslationEngine\Enums\TranslationDataClassification;
use App\Support\TranslationEngine\Providers\OpenAiTranslationProvider;

/*
|--------------------------------------------------------------------------
| Translation engine
|--------------------------------------------------------------------------
|
| The machine-translation engine consumers reach through
| App\Support\TranslationEngine\TranslationService. See
| docs/architecture/translation-engine.md.
|
| Nothing here is needed to boot. With TRANSLATION_OPENAI_API_KEY empty — the
| local, CI and freshly provisioned state — the application, its queues and
| the admin run exactly as before; only an actual translation is refused, as
| not configured, before any request is sent.
|
*/

return [

    // The registry name of the provider every batch is routed to.
    'default' => env('TRANSLATION_PROVIDER', 'openai'),

    // The ceiling on one logical batch — one TranslationService call — a guard
    // against a runaway consumer, not a working size. Characters are counted
    // over what a provider would be sent: instructions and JSON payload. Work
    // larger than this is several batches.
    'max_batch_items' => 500,
    'max_batch_chars' => 500_000,

    // Generate missing: AI drafts for every missing translation of a language,
    // made in the background by queued jobs and kept, until an administrator
    // saves or discards them, in this cache store — temporary workflow state,
    // never project content. A batch expires a fixed time after it was
    // created; reading it does not extend it. A chunk a worker claimed and
    // never finished counts as interrupted after stale_running_seconds, and
    // one no worker has taken stale_queued_seconds after its batch was
    // created — a queue that lost the job, or has no worker — does too.
    'bulk' => [
        'cache_store' => 'redis',
        'ttl_seconds' => 172800,
        'stale_running_seconds' => 180,
        'stale_queued_seconds' => 3600,
    ],

    // Every provider the router can choose, by name. `driver` is a class
    // implementing App\Support\TranslationEngine\Contracts\TranslationProvider;
    // it reads the rest of its own entry. A provider is added here, without
    // changing the router or any consumer.
    //
    // `allowed_classifications` is the privacy boundary: the data
    // classifications this provider may receive. An entry that lists none
    // receives none. An external provider never lists private content.
    'providers' => [

        'openai' => [
            'driver' => OpenAiTranslationProvider::class,

            // Supplied only in the target host's shared .env. Never committed,
            // never a workflow input, never a GitHub environment secret.
            'api_key' => env('TRANSLATION_OPENAI_API_KEY'),

            'model' => env('TRANSLATION_OPENAI_MODEL', 'gpt-6-luna'),
            'base_url' => env('TRANSLATION_OPENAI_BASE_URL', 'https://api.openai.com/v1'),

            // Seconds. There are no retries: a retry can silently double a
            // paid call.
            'connect_timeout' => 5,
            'timeout' => 45,

            // One request closes at whichever it reaches first.
            'max_items' => 50,
            'max_payload_chars' => 60_000,

            'allowed_classifications' => [
                TranslationDataClassification::PublicContent->value,
                TranslationDataClassification::PublicUserGenerated->value,
            ],
        ],

    ],

];
