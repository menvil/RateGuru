<?php

namespace App\Support\Import;

use App\Enums\MediaTool;
use App\Services\Media\Exceptions\MediaToolUnavailableException;
use App\Services\Media\VideoIngestPolicy;
use Closure;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * yt-dlp opens the addresses a site's pages hand it, outside the pinned
 * import client. On a host prepared for it, each target's worker runs yt-dlp
 * as an account of that target's own — its runtime user's name and
 * import.ytdlp.isolation_suffix, "rateguru-staging-fetch" — through the one
 * sudo rule that lets it run yt-dlp as that account and nothing else, while
 * the host's firewall keeps every such account off this host's network
 * (infrastructure/scripts/install-media-fetch-isolation). An account per
 * target, so one target's yt-dlp can never reach another's downloads.
 *
 * Where the account does not exist — development, tests, a host not
 * prepared yet — yt-dlp runs as the worker, as before. Where it exists but
 * the firewall is not loaded (the marker its unit keeps in /run is missing),
 * yt-dlp is not run at all: never as that account without the table.
 *
 * A download's directory, inside the worker's own, is opened to the account
 * by ACL for the run and closed again after it; the file it wrote is checked
 * — a plain file, no link — and copied, so what the worker stores is its own.
 */
final class YtDlpIsolation
{
    private const string SUDO = '/usr/bin/sudo';

    /** What the worker stores in place of the file the account wrote. */
    public const string TAKEN = 'taken';

    /**
     * @param  (Closure(string): bool)|null  $accountExists
     * @param  (Closure(list<string>): bool)|null  $setfacl  runs setfacl with the arguments; whether it succeeded
     * @param  (Closure(): bool)|null  $armed  whether the firewall's marker is there
     */
    public function __construct(
        private readonly ?Closure $accountExists = null,
        private readonly ?Closure $setfacl = null,
        private readonly ?Closure $armed = null,
    ) {}

    /** The account yt-dlp runs as for this worker, or null to run it as the worker. */
    public function user(): ?string
    {
        $suffix = config('import.ytdlp.isolation_suffix');
        $worker = self::worker();

        if (! is_string($suffix) || $suffix === '' || $worker === null) {
            return null;
        }

        $user = $worker.$suffix;
        $exists = $this->accountExists ?? fn (string $name): bool => function_exists('posix_getpwnam') && posix_getpwnam($name) !== false;

        return $exists($user) ? $user : null;
    }

    /**
     * The command that starts yt-dlp: the binary itself, or sudo running it
     * as the account — non-interactively, so a missing rule fails at once.
     *
     * @return list<string>
     *
     * @throws MediaToolUnavailableException when the account exists but the firewall that holds it is not loaded
     */
    public function command(string $binary): array
    {
        $user = $this->user();

        // A host prepared for isolation (its marker is there) that has no
        // account for this worker — a target added since, a name too long
        // for one — never runs yt-dlp as the worker, outside the firewall.
        if ($user === null) {
            return $this->armed() ? throw MediaToolUnavailableException::cannotStart(MediaTool::YtDlp) : [$binary];
        }

        if (! $this->armed()) {
            throw MediaToolUnavailableException::cannotStart(MediaTool::YtDlp);
        }

        return [self::SUDO, '-n', '-u', $user, '--', $binary];
    }

    /**
     * Opens $directory, inside the worker's own, to the account for the run:
     * it may pass through the parent and write here, and whatever it writes
     * stays the worker's to read and remove.
     *
     * @throws RuntimeException when the directory cannot be opened to it
     */
    public function share(string $directory): void
    {
        $user = $this->user();

        if ($user === null) {
            return;
        }

        // Nothing is opened to the account unless it may run at all.
        if (! $this->armed()) {
            throw MediaToolUnavailableException::cannotStart(MediaTool::YtDlp);
        }

        $worker = self::worker() ?? throw new RuntimeException('The worker\'s own account could not be named.');

        $opened = $this->setfacl(['-m', "u:{$user}:x", dirname($directory)])
            && $this->setfacl(['-m', "u:{$user}:rwx,d:u:{$user}:rwx,d:u:{$worker}:rwx", $directory]);

        if (! $opened) {
            throw new RuntimeException('The download directory could not be opened to yt-dlp\'s account.');
        }
    }

    /**
     * After the run: the directory closed to the account again — from here on
     * it can neither add, replace nor remove anything in it — then the file it
     * wrote, if it is a plain file and no link, copied to the worker's own.
     *
     * @return string|null the worker's copy, or null when nothing it may take is there
     *
     * @throws RuntimeException when the directory cannot be closed to the account
     */
    public function take(string $directory, ?string $written): ?string
    {
        $user = $this->user();

        if ($user === null) {
            return $written;
        }

        $this->close($directory);

        clearstatcache();

        // A plain file with one name, never larger than an upload may be:
        // what an account that ignored its limits wrote is never copied whole.
        if ($written === null || is_link($written) || ! is_file($written) || (stat($written)['nlink'] ?? 0) !== 1
            || (int) filesize($written) > VideoIngestPolicy::fromConfig()->maxBytes) {
            return null;
        }

        $copy = $directory.'/'.self::TAKEN.'.'.pathinfo($written, PATHINFO_EXTENSION);

        if (! copy($written, $copy)) {
            report(new RuntimeException('A file yt-dlp wrote could not be copied into the worker\'s own.'));

            return null;
        }

        return $copy;
    }

    /**
     * Closes $directory to the account again — after a run that ended in an
     * exception too, before the directory is removed — so nothing it started
     * can go on writing there.
     *
     * @throws RuntimeException when the directory cannot be closed to it
     */
    public function close(string $directory): void
    {
        $user = $this->user();

        if ($user !== null && is_dir($directory) && ! $this->setfacl(['-x', "u:{$user},d:u:{$user}", $directory])) {
            throw new RuntimeException('The download directory could not be closed to yt-dlp\'s account again.');
        }
    }

    /**
     * Whether a failed run was sudo refusing it — the host's rule missing —
     * rather than yt-dlp's own failure. Only on a non-zero exit, and only by
     * what sudo says when it refuses: a warning sudo prints before running
     * the command ("unable to resolve host") is no refusal.
     */
    public static function refused(int $exitCode, string $errorOutput): bool
    {
        return $exitCode !== 0 && preg_match('/^sudo: (?:a password is required|a terminal is required|.* is not allowed to execute|unknown user|.*no new privileges)/m', $errorOutput) === 1;
    }

    private function armed(): bool
    {
        if ($this->armed !== null) {
            return ($this->armed)();
        }

        $marker = config('import.ytdlp.isolation_marker');

        return is_string($marker) && $marker !== '' && is_file($marker);
    }

    /** @param  list<string>  $arguments */
    private function setfacl(array $arguments): bool
    {
        if ($this->setfacl !== null) {
            return ($this->setfacl)($arguments);
        }

        $process = new Process(['/usr/bin/setfacl', ...$arguments]);
        $process->run();

        return $process->isSuccessful();
    }

    private static function worker(): ?string
    {
        $name = function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? null) : null;

        return is_string($name) && $name !== '' ? $name : null;
    }
}
