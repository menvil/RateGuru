<?php

use App\Support\Configuration\EnvironmentValue;

/*
 * A number left blank in the environment — the templates' way of saying "not
 * configured for this target" — keeps the config file's default, where a
 * plain `(int) env()` made it 0.
 */

/** A config file as it reads with these environment values. */
function configFileWith(string $file, array $environment): array
{
    $saved = [];

    foreach ($environment as $key => $value) {
        $saved[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null];
        $_SERVER[$key] = $_ENV[$key] = $value;
    }

    try {
        return require base_path("config/{$file}.php");
    } finally {
        foreach ($saved as $key => [$server, $env]) {
            unset($_SERVER[$key], $_ENV[$key]);

            if ($server !== null) {
                $_SERVER[$key] = $server;
            }

            if ($env !== null) {
                $_ENV[$key] = $env;
            }
        }
    }
}

it('keeps the default for a blank value or one that is no number, and takes any number', function () {
    expect(EnvironmentValue::integer(null, 90))->toBe(90)
        ->and(EnvironmentValue::integer('', 90))->toBe(90)
        ->and(EnvironmentValue::integer('ninety', 90))->toBe(90)
        ->and(EnvironmentValue::integer('0', 90))->toBe(0)
        ->and(EnvironmentValue::integer('150', 90))->toBe(150);
});

it('reads the defaults for the numbers the templates may leave blank', function (string $file, string $key, string $path, int $default) {
    expect(data_get(configFileWith($file, [$key => '']), $path))->toBe($default);
})->with([
    'redis retry_after' => ['queue', 'REDIS_QUEUE_RETRY_AFTER', 'connections.redis.retry_after', 90],
    'database retry_after' => ['queue', 'DB_QUEUE_RETRY_AFTER', 'connections.database.retry_after', 90],
    'beanstalkd retry_after' => ['queue', 'BEANSTALKD_QUEUE_RETRY_AFTER', 'connections.beanstalkd.retry_after', 90],
    'session lifetime' => ['session', 'SESSION_LIFETIME', 'lifetime', 120],
    'upload size' => ['uploads', 'UPLOAD_IMAGE_MAX_KB', 'images.max_kilobytes', 5120],
    'upload width' => ['uploads', 'UPLOAD_IMAGE_MAX_WIDTH', 'images.max_width', 6000],
    'upload attempts' => ['rate_limits', 'RATE_LIMIT_UPLOAD_ATTEMPTS', 'upload.max_attempts', 5],
    'upload window' => ['rate_limits', 'RATE_LIMIT_UPLOAD_DECAY_SECONDS', 'upload.decay_seconds', 600],
    'vote attempts' => ['rate_limits', 'RATE_LIMIT_VOTE_ATTEMPTS', 'vote.max_attempts', 60],
    'media purge grace' => ['media', 'MEDIA_PURGE_GRACE_DAYS', 'lifecycle.purge_grace_days', 7],
]);

it('still holds a number the config clamps to its minimum', function () {
    expect(data_get(configFileWith('uploads', ['UPLOAD_IMAGE_MAX_WIDTH' => '0']), 'images.max_width'))->toBe(1);
});
