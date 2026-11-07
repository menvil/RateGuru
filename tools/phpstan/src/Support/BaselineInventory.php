<?php

declare(strict_types=1);

namespace RateGuru\PHPStan\Support;

use RuntimeException;

use function array_key_exists;
use function array_sum;
use function count;
use function file;
use function is_file;
use function ksort;
use function preg_match;
use function sprintf;
use function str_starts_with;
use function trim;

final class BaselineInventory
{
    /**
     * @param  list<array{message: string, identifier: string, count: int, path: string}>  $entries
     */
    private function __construct(private array $entries) {}

    public static function fromFile(string $path): self
    {
        if (! is_file($path)) {
            return new self([]);
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            throw new RuntimeException("Unable to read PHPStan baseline at {$path}.");
        }

        $entries = [];
        $entry = [];

        foreach ($lines as $line) {
            if (preg_match('/^\s*(message|identifier|count|path):\s*(.+)$/', $line, $matches) !== 1) {
                continue;
            }

            $key = $matches[1];
            $value = trim($matches[2]);

            if ($key === 'message' && $entry !== []) {
                self::appendEntry($entries, $entry);
                $entry = [];
            }

            $entry[$key] = $key === 'count' ? (int) $value : self::unquote($value);
        }

        if ($entry !== []) {
            self::appendEntry($entries, $entry);
        }

        return new self($entries);
    }

    /** @return list<array{message: string, identifier: string, count: int, path: string}> */
    public function entries(): array
    {
        return $this->entries;
    }

    public function findings(): int
    {
        return array_sum(array_column($this->entries, 'count'));
    }

    public function patterns(): int
    {
        return count($this->entries);
    }

    public function files(): int
    {
        $paths = [];

        foreach ($this->entries as $entry) {
            $paths[$entry['path']] = true;
        }

        return count($paths);
    }

    /** @return array<string, int> */
    public function findingsByIdentifier(): array
    {
        $findings = [];

        foreach ($this->entries as $entry) {
            $findings[$entry['identifier']] = ($findings[$entry['identifier']] ?? 0) + $entry['count'];
        }

        ksort($findings);

        return $findings;
    }

    public function architectureFindings(): int
    {
        $findings = 0;

        foreach ($this->entries as $entry) {
            if (str_starts_with($entry['identifier'], 'rateguru.')) {
                $findings += $entry['count'];
            }
        }

        return $findings;
    }

    public function findingsDeltaFrom(self $base): int
    {
        return $this->findings() - $base->findings();
    }

    /** @return list<string> */
    public function growthComparedTo(self $base): array
    {
        $baseEntries = $base->indexedEntries();
        $violations = [];

        foreach ($this->entries as $entry) {
            $key = self::entryKey($entry);

            if (! array_key_exists($key, $baseEntries)) {
                $violations[] = sprintf(
                    'New baseline suppression: %s in %s (count: %d).',
                    $entry['identifier'],
                    $entry['path'],
                    $entry['count'],
                );

                continue;
            }

            if ($entry['count'] > $baseEntries[$key]['count']) {
                $violations[] = sprintf(
                    'Baseline suppression count increased: %s in %s (%d -> %d).',
                    $entry['identifier'],
                    $entry['path'],
                    $baseEntries[$key]['count'],
                    $entry['count'],
                );
            }
        }

        return $violations;
    }

    /** @return array<string, array{message: string, identifier: string, count: int, path: string}> */
    private function indexedEntries(): array
    {
        $entries = [];

        foreach ($this->entries as $entry) {
            $entries[self::entryKey($entry)] = $entry;
        }

        return $entries;
    }

    /**
     * @param  array{message: string, identifier: string, count: int, path: string}  $entry
     */
    private static function entryKey(array $entry): string
    {
        return $entry['identifier']."\0".$entry['message']."\0".$entry['path'];
    }

    /**
     * @param  list<array{message: string, identifier: string, count: int, path: string}>  $entries
     * @param  array<string, int|string>  $entry
     */
    private static function appendEntry(array &$entries, array $entry): void
    {
        foreach (['message', 'identifier', 'count', 'path'] as $key) {
            if (! array_key_exists($key, $entry)) {
                throw new RuntimeException("Malformed PHPStan baseline entry is missing {$key}.");
            }
        }

        $entries[] = [
            'message' => (string) $entry['message'],
            'identifier' => (string) $entry['identifier'],
            'count' => (int) $entry['count'],
            'path' => (string) $entry['path'],
        ];
    }

    private static function unquote(string $value): string
    {
        if (preg_match("/^'(.*)'$/s", $value, $matches) === 1) {
            return $matches[1];
        }

        return $value;
    }
}
