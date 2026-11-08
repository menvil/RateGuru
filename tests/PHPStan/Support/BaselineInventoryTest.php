<?php

declare(strict_types=1);

namespace Tests\PHPStan\Support;

use PHPUnit\Framework\TestCase;
use RateGuru\PHPStan\Support\BaselineInventory;

final class BaselineInventoryTest extends TestCase
{
    public function test_it_reports_findings_patterns_files_and_identifiers(): void
    {
        $inventory = BaselineInventory::fromFile(__DIR__.'/../Fixtures/Baseline/base.neon');

        self::assertSame(3, $inventory->findings());
        self::assertSame(2, $inventory->patterns());
        self::assertSame(2, $inventory->files());
        self::assertSame([
            'argument.type' => 1,
            'property.notFound' => 2,
        ], $inventory->findingsByIdentifier());
        self::assertSame(0, $inventory->architectureFindings());
    }

    public function test_it_reports_architecture_suppressions(): void
    {
        $inventory = BaselineInventory::fromFile(__DIR__.'/../Fixtures/Baseline/architecture.neon');

        self::assertSame(1, $inventory->architectureFindings());
    }

    public function test_it_accepts_only_removals_and_count_reductions(): void
    {
        $base = BaselineInventory::fromFile(__DIR__.'/../Fixtures/Baseline/base.neon');
        $reduced = BaselineInventory::fromFile(__DIR__.'/../Fixtures/Baseline/reduced.neon');

        self::assertSame([], $reduced->growthComparedTo($base));
        self::assertSame(-2, $reduced->findingsDeltaFrom($base));
    }

    public function test_it_rejects_new_patterns_even_when_the_total_does_not_grow(): void
    {
        $base = BaselineInventory::fromFile(__DIR__.'/../Fixtures/Baseline/base.neon');
        $replacement = BaselineInventory::fromFile(__DIR__.'/../Fixtures/Baseline/replacement.neon');

        self::assertSame([
            'New baseline suppression: return.type in app/Actions/NewAction.php (count: 1).',
        ], $replacement->growthComparedTo($base));
    }

    public function test_it_rejects_a_suppression_entered_twice_as_it_rejects_a_raised_count(): void
    {
        $base = BaselineInventory::fromFile(__DIR__.'/../Fixtures/Baseline/base.neon');
        $duplicated = BaselineInventory::fromFile(__DIR__.'/../Fixtures/Baseline/duplicated.neon');

        self::assertSame([
            'Baseline suppression count increased: property.notFound in app/Models/Post.php (2 -> 4).',
        ], $duplicated->growthComparedTo($base));
    }

    public function test_it_reads_a_suppression_entered_twice_in_the_base_as_its_total(): void
    {
        $duplicated = BaselineInventory::fromFile(__DIR__.'/../Fixtures/Baseline/duplicated.neon');
        $merged = BaselineInventory::fromFile(__DIR__.'/../Fixtures/Baseline/merged.neon');

        // Two entries of 2 in the base, one of 4 now: no more than there was.
        self::assertSame([], $merged->growthComparedTo($duplicated));
    }

    public function test_a_missing_baseline_is_an_empty_inventory(): void
    {
        $inventory = BaselineInventory::fromFile(__DIR__.'/../Fixtures/Baseline/missing.neon');

        self::assertSame(0, $inventory->findings());
        self::assertSame(0, $inventory->patterns());
        self::assertSame([], $inventory->entries());
    }
}
