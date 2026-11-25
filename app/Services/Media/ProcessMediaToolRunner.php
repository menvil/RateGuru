<?php

namespace App\Services\Media;

use App\Enums\MediaTool;
use App\Services\Media\Exceptions\MediaToolTimedOutException;
use App\Services\Media\Exceptions\MediaToolUnavailableException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * MediaToolRunner through Symfony Process with an array command, which PHP
 * starts directly — there is no shell between the arguments and the tool.
 *
 * The binary is resolved first: Symfony retries a program that will not
 * start through `sh -c exec ...`, whose answer (126 or 127, depending on the
 * shell) would then have to be told apart from the tool's own exit codes.
 * Resolving it up front makes a missing tool a clear "could not be started"
 * and means the command run is always the tool itself. Messages carry the
 * tool's name only, never a path or its output: the caller decides what, if
 * anything, of the output is worth logging.
 */
final class ProcessMediaToolRunner implements MediaToolRunner
{
    public function run(MediaTool $tool, array $arguments, int $timeoutSeconds): MediaToolResult
    {
        $binary = $this->resolve($tool);

        // A fixed locale keeps the tools' number formatting (ffprobe's
        // durations) the same whatever the worker's environment says.
        $process = new Process([$binary, ...$arguments], null, ['LC_ALL' => 'C'], null, $timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessTimedOutException $exception) {
            throw MediaToolTimedOutException::after($tool, $timeoutSeconds, $exception);
        } catch (ProcessRuntimeException $exception) {
            throw MediaToolUnavailableException::cannotStart($tool, $exception);
        }

        return new MediaToolResult($process->getExitCode() ?? -1, $process->getOutput(), $process->getErrorOutput());
    }

    /**
     * The configured binary as a path to an executable file. A bare name is
     * looked up on PATH, then in config('media.video.search_directories'):
     * PHP-FPM clears its workers' environment, so a probe run while a request
     * uploads a video has no PATH at all, and the directories are what find
     * the tool there — /usr/local/bin first, where install-bootstrap-runtime
     * puts the pinned build, ahead of a distribution copy in /usr/bin.
     */
    private function resolve(MediaTool $tool): string
    {
        $configured = $tool->binary();

        $binary = str_contains($configured, '/')
            ? (is_file($configured) && is_executable($configured) ? $configured : null)
            : (new ExecutableFinder)->find($configured, null, $this->searchDirectories());

        return $binary ?? throw MediaToolUnavailableException::cannotStart($tool);
    }

    /** @return list<string> */
    private function searchDirectories(): array
    {
        return array_values(array_filter(
            (array) config('media.video.search_directories', []),
            fn (mixed $directory): bool => is_string($directory) && $directory !== '',
        ));
    }
}
