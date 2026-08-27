<?php

namespace App\Support\Deployment;

use JsonException;

/**
 * Read-only view of the canonical release metadata baked into every immutable
 * RateGuru artifact.
 *
 * The build pipeline (.github/workflows/deploy-staging.yml and release.yml)
 * writes `release.json` into the package root before the artifact is sealed,
 * and infrastructure/scripts/deploy extracts that artifact untouched. This
 * class is the only place the application reads it, so there is exactly one
 * release identity — the same string that appears in the deployment history,
 * in the `current` symlink target, and in the GitHub deployment.
 *
 * It deliberately never shells out to Git: production and staging releases are
 * immutable directories with no `.git` in them, and a runtime that needed one
 * would report a release the server is not actually running.
 *
 * Absent or malformed metadata is never repaired or guessed — the value stays
 * `null` so Sentry records "no release" rather than a fabricated one.
 */
final class DeploymentMetadata
{
    /** The metadata file was present, parsable and carried valid values. */
    public const STATE_PRESENT = 'present';

    /** No release.json at all — the normal state of a working copy. */
    public const STATE_MISSING = 'missing';

    /** A release.json exists but is unreadable, unparsable or out of contract. */
    public const STATE_MALFORMED = 'malformed';

    /**
     * The exact shape the build pipeline produces, and the same expression the
     * deploy-rateguru action validates before an artifact may reach a server.
     */
    private const RELEASE_PATTERN = '/^v[0-9]+\.[0-9]+\.[0-9]+-[0-9]{8}-[0-9]{6}-[0-9a-f]{7,40}$/';

    private const COMMIT_PATTERN = '/^[0-9a-f]{7,40}$/';

    private function __construct(
        private readonly ?string $release,
        private readonly ?string $commit,
        private readonly string $state,
    ) {}

    public static function fromBasePath(string $basePath): self
    {
        return self::fromFile(rtrim($basePath, '/').'/'.self::fileName());
    }

    public static function fromFile(string $path): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            return new self(null, null, self::STATE_MISSING);
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            return new self(null, null, self::STATE_MALFORMED);
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new self(null, null, self::STATE_MALFORMED);
        }

        if (! is_array($decoded)) {
            return new self(null, null, self::STATE_MALFORMED);
        }

        $release = self::matching($decoded['release'] ?? null, self::RELEASE_PATTERN);
        $commit = self::matching($decoded['source_sha'] ?? null, self::COMMIT_PATTERN);

        // A half-valid file is reported as malformed but still surrenders the
        // half that is trustworthy: dropping a genuine release ID because the
        // commit was mangled would lose the correlation this whole file exists
        // for, and inventing the missing half is never an option.
        if ($release === null || $commit === null) {
            return new self($release, $commit, self::STATE_MALFORMED);
        }

        return new self($release, $commit, self::STATE_PRESENT);
    }

    public static function fileName(): string
    {
        return 'release.json';
    }

    public function release(): ?string
    {
        return $this->release;
    }

    public function commit(): ?string
    {
        return $this->commit;
    }

    public function state(): string
    {
        return $this->state;
    }

    private static function matching(mixed $value, string $pattern): ?string
    {
        if (! is_string($value) || preg_match($pattern, $value) !== 1) {
            return null;
        }

        return $value;
    }
}
