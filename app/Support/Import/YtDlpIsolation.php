<?php

namespace App\Support\Import;

use Closure;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * yt-dlp opens the addresses a site's pages hand it, outside the pinned
 * import client. On a host prepared for it, it runs as an account of its own
 * (import.ytdlp.isolation_user) that the host's firewall keeps off every
 * loopback, private and link-local address, through the one sudo rule that
 * lets the target's runtime user run yt-dlp as that account and nothing else
 * (infrastructure/scripts/install-media-fetch-isolation). Where the account
 * does not exist — development, tests, a host not prepared yet — yt-dlp
 * runs as the worker, as before.
 *
 * Its downloads land in the worker's own directory: that directory is opened
 * to the account, by ACL, for the run.
 */
final class YtDlpIsolation
{
    private const string SUDO = '/usr/bin/sudo';

    /**
     * @param  (Closure(string): bool)|null  $accountExists
     * @param  (Closure(list<string>): bool)|null  $setfacl  runs setfacl with the arguments; whether it succeeded
     */
    public function __construct(
        private readonly ?Closure $accountExists = null,
        private readonly ?Closure $setfacl = null,
    ) {}

    /** The account yt-dlp runs as on this host, or null to run it as the worker. */
    public function user(): ?string
    {
        $user = config('import.ytdlp.isolation_user');

        if (! is_string($user) || $user === '') {
            return null;
        }

        $exists = $this->accountExists ?? fn (string $name): bool => function_exists('posix_getpwnam') && posix_getpwnam($name) !== false;

        return $exists($user) ? $user : null;
    }

    /**
     * The command that starts yt-dlp: the binary itself, or sudo running it
     * as the account — non-interactively, so a missing rule fails at once.
     *
     * @return list<string>
     */
    public function command(string $binary): array
    {
        $user = $this->user();

        return $user === null ? [$binary] : [self::SUDO, '-n', '-u', $user, '--', $binary];
    }

    /**
     * Opens $directory, inside the worker's own, to the account: it may pass
     * through the parent and write here, and whatever it writes stays the
     * worker's to read and remove.
     *
     * @throws RuntimeException when the directory cannot be opened to it
     */
    public function share(string $directory): void
    {
        $user = $this->user();

        if ($user === null) {
            return;
        }

        $worker = function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? null) : null;

        if (! is_string($worker) || $worker === '') {
            throw new RuntimeException('The worker\'s own account could not be named.');
        }

        $setfacl = $this->setfacl ?? function (array $arguments): bool {
            $process = new Process(['setfacl', ...$arguments]);
            $process->run();

            return $process->isSuccessful();
        };

        $opened = $setfacl(['-m', "u:{$user}:x", dirname($directory)])
            && $setfacl(['-m', "u:{$user}:rwx,d:u:{$user}:rwx,d:u:{$worker}:rwx", $directory]);

        if (! $opened) {
            throw new RuntimeException('The download directory could not be opened to yt-dlp\'s account.');
        }
    }

    /** Whether a run's error output is sudo refusing it — the host's rule missing — rather than yt-dlp's own failure. */
    public static function refused(string $errorOutput): bool
    {
        return str_starts_with(ltrim($errorOutput), 'sudo:');
    }
}
