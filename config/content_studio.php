<?php

/*
|--------------------------------------------------------------------------
| Content Studio
|--------------------------------------------------------------------------
|
| Settings of Content Studio's own integrations. See
| docs/architecture/studio-plans.md.
|
| Nothing here is needed to boot. Photo analysis is off unless
| CONTENT_STUDIO_OPENAI_ENABLED is true and CONTENT_STUDIO_OPENAI_API_KEY is
| set — the local, CI and freshly provisioned state is off — and while it is
| off the application, its queues and the admin run exactly as they do with
| it on; only Analyze photo says it is not configured, and nothing is sent.
|
*/

return [

    // Photo analysis: a draft's saved photo described by OpenAI's Responses
    // API, on an administrator's explicit request, as structured technical
    // context for the text generators. One request per analysis, made by a
    // queued job (AnalyzeStudioPlanImageJob); never retried by itself.
    //
    // Its own key and settings — not Translation AI's: the two are switched,
    // billed and rotated apart. The key lives only in the target's
    // shared/.env, never in the repository.
    'image_analysis' => [
        // true, 1, yes or on; anything else, or nothing, leaves it off.
        'enabled' => filter_var(env('CONTENT_STUDIO_OPENAI_ENABLED', false), FILTER_VALIDATE_BOOL),
        'api_key' => env('CONTENT_STUDIO_OPENAI_API_KEY'),
        'model' => env('CONTENT_STUDIO_OPENAI_MODEL', 'gpt-4.1-mini'),
        'base_url' => env('CONTENT_STUDIO_OPENAI_BASE_URL', 'https://api.openai.com/v1'),

        // Seconds. The request's timeout sits inside the job's (75 s), which
        // sits inside the Redis queue's retry_after (90 s), so a job is
        // stopped before the queue would hand it to another worker.
        'connect_timeout' => 5,
        'timeout' => 45,

        // The ceiling on what one answer may spend; a description that would
        // need more comes back incomplete, and is refused as such.
        'max_output_tokens' => 800,

        // The most of a stored photo that is read to be sent. The ~1280 px
        // feed variant is used when there is one, so this is seldom near.
        'max_image_bytes' => 8 * 1024 * 1024,

        // An attempt still waiting or running this long after it was
        // requested or started is taken as lost — its worker died, or no
        // worker took it — and may be started again. Well past the job's own
        // timeout, so a second attempt never runs beside a live first one.
        'stale_after_seconds' => 180,
    ],

];
