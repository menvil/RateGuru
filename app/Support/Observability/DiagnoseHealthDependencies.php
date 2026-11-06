<?php

namespace App\Support\Observability;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Events\DiagnosingHealth;
use RuntimeException;
use Throwable;

/**
 * What /up answers for besides the application booting: the database and the
 * default cache store, which every page reads.
 *
 * Laravel's health route turns an exception thrown here into a 500 and
 * reports it. So the health check a deploy, a restore and a recovery each run
 * sees a host that cannot serve a page, not merely a framework that starts.
 * The page itself says only that the application is experiencing problems;
 * which dependency failed, and why, goes to the error report.
 */
final class DiagnoseHealthDependencies
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly CacheFactory $cache,
    ) {}

    public function handle(DiagnosingHealth $event): void
    {
        try {
            $this->database->connection()->select('select 1');
        } catch (Throwable $e) {
            throw new RuntimeException('The database cannot be reached.', previous: $e);
        }

        try {
            $this->cache->store()->get('health:probe');
        } catch (Throwable $e) {
            throw new RuntimeException('The cache store cannot be reached.', previous: $e);
        }
    }
}
