<?php

use App\Support\Deployment\DeploymentMetadata;

$metadata = DeploymentMetadata::fromBasePath(dirname(__DIR__));

$target = env('APP_DEPLOYMENT_TARGET');

// Target IDs are the registry's own slug shape (infrastructure/config/
// deployment-targets.json). Anything else is treated as unconfigured rather
// than passed through: a malformed value would become a junk Sentry tag that
// silently splits one target's events into two.
if (! is_string($target) || preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $target) !== 1) {
    $target = null;
}

return [

    /*
    |--------------------------------------------------------------------------
    | Deployment target
    |--------------------------------------------------------------------------
    |
    | Which target of the RateGuru deployment registry this runtime is serving
    | (staging-main, tits-guru, ...). It is static per target and lives in that
    | target's shared .env — deployments never rewrite it. The environment
    | class (staging/production) is a separate concept and stays APP_ENV.
    |
    */

    'target' => $target,

    /*
    |--------------------------------------------------------------------------
    | Canonical release identity
    |--------------------------------------------------------------------------
    |
    | Read from the release.json the build pipeline seals into the artifact —
    | never from Git, which does not exist inside a deployed release. Both
    | values are null when the metadata is absent or out of contract; nothing
    | here ever fabricates a stand-in release.
    |
    */

    'release' => $metadata->release(),

    'commit' => $metadata->commit(),

    'metadata_state' => $metadata->state(),

    'metadata_file' => DeploymentMetadata::fileName(),

];
