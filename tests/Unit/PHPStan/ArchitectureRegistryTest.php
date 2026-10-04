<?php

declare(strict_types=1);

namespace Tests\Unit\PHPStan;

use App\Contracts\Persistence\RawSqlPersistenceBoundary;
use App\Contracts\Persistence\StablePaginationBoundary;
use PHPStan\Testing\PHPStanTestCase;
use ReflectionClass;

final class ArchitectureRegistryTest extends PHPStanTestCase
{
    /** @var array<string, mixed> */
    private static array $architecture;

    /** @return list<string> */
    public static function getAdditionalConfigFiles(): array
    {
        return [dirname(__DIR__, 3).'/phpstan.neon'];
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $architecture = self::getContainer()->getParameter('architecture');
        self::assertIsArray($architecture);
        self::$architecture = $architecture;
    }

    public static function tearDownAfterClass(): void
    {
        restore_error_handler();
        restore_exception_handler();

        parent::tearDownAfterClass();
    }

    public function test_raw_sql_entries_are_exact_tested_and_not_stale(): void
    {
        $seen = [];

        foreach ($this->entries('rawSqlExceptions') as $entry) {
            $this->assertSame('approved', $entry['status'] ?? null);
            $this->assertContains($entry['bindings'] ?? null, ['required', 'literal_only', 'internal_only']);
            $this->assertNotSame('', trim((string) ($entry['reason'] ?? '')));
            $this->assertRegisteredClassUsesBoundary($entry, RawSqlPersistenceBoundary::class, 'App\\Queries\\');
            $this->assertBehaviorTestsExist($entry);

            $reflection = new ReflectionClass((string) $entry['class']);
            $source = file_get_contents((string) $reflection->getFileName());
            $this->assertIsString($source);

            foreach ($entry['methods'] ?? [] as $method) {
                $key = $entry['class'].'::'.$method;
                $this->assertArrayNotHasKey($key, $seen, "Duplicate raw SQL exception {$key}.");
                $this->assertMatchesRegularExpression('/->\s*'.preg_quote((string) $method, '/').'\s*\(/', $source, "Stale raw SQL exception {$key}.");
                $seen[$key] = true;
            }
        }
    }

    public function test_there_are_no_low_level_database_exceptions(): void
    {
        $this->assertSame([], self::$architecture['lowLevelDatabaseExceptions'] ?? null);
    }

    public function test_pagination_boundaries_are_registered_and_behavior_tested(): void
    {
        foreach ($this->entries('paginationBoundaries') as $entry) {
            $this->assertSame('approved', $entry['status'] ?? null);
            $this->assertNotEmpty($entry['uniqueOrder'] ?? []);
            $this->assertRegisteredClassUsesBoundary($entry, StablePaginationBoundary::class, 'App\\Queries\\');
            $this->assertTrue(method_exists((string) $entry['class'], (string) $entry['method']));
            $this->assertBehaviorTestsExist($entry);
        }
    }

    public function test_registered_behavior_tests_are_in_their_canonical_suites(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($composer);
        $scripts = $composer['scripts'] ?? null;
        $this->assertIsArray($scripts);

        $databaseSuite = $this->scriptCommand($scripts['test:database-boundaries'] ?? null);
        $paginationSuite = $this->scriptCommand($scripts['test:pagination-boundaries'] ?? null);

        foreach ($this->entries('rawSqlExceptions') as $entry) {
            foreach ($entry['behaviorTests'] ?? [] as $test) {
                $this->assertStringContainsString((string) $test, $databaseSuite);
            }
        }

        foreach ($this->entries('paginationBoundaries') as $entry) {
            foreach ($entry['behaviorTests'] ?? [] as $test) {
                $this->assertStringContainsString((string) $test, $paginationSuite);
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function entries(string $registry): array
    {
        $entries = self::$architecture[$registry] ?? null;
        $this->assertIsArray($entries);
        $this->assertNotEmpty($entries);

        return $entries;
    }

    /** @param array<string, mixed> $entry */
    private function assertBehaviorTestsExist(array $entry): void
    {
        $tests = $entry['behaviorTests'] ?? null;
        $this->assertIsArray($tests);
        $this->assertNotEmpty($tests);

        foreach ($tests as $test) {
            $this->assertFileExists(dirname(__DIR__, 3).'/'.$test);
        }
    }

    /** @param array<string, mixed> $entry */
    private function assertRegisteredClassUsesBoundary(array $entry, string $boundary, string $namespace): void
    {
        $class = $entry['class'] ?? null;
        $this->assertIsString($class);
        $this->assertStringStartsWith($namespace, $class);
        $this->assertTrue((new ReflectionClass($class))->implementsInterface($boundary));
    }

    private function scriptCommand(mixed $script): string
    {
        if (is_string($script)) {
            return $script;
        }

        $this->assertIsArray($script);

        return implode("\n", $script);
    }
}
