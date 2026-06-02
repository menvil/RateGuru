# Queue Worker

## Current MVP

RateGuru can run with `QUEUE_CONNECTION=sync` when no async jobs are enabled.

Redis is not required by Phase 42.

## Async queue rule

Do not enable an async queue driver without a running worker.

If `QUEUE_CONNECTION=database` or `QUEUE_CONNECTION=redis` is used later, run:

```bash
php artisan queue:work --tries=3 --timeout=90
```

Restart workers after deploys that change code:

```bash
php artisan queue:restart
```

## Example Supervisor program

This is illustrative, not mandatory:

```ini
[program:rateguru-worker]
command=php /path/to/rateguru/artisan queue:work --tries=3 --timeout=90
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/path/to/rateguru/storage/logs/worker.log
```

## Operations

- Monitor worker logs.
- Confirm failed job storage is configured if async queues are enabled.
- Keep `QUEUE_CONNECTION=sync` for simple MVP deployments that do not need background processing.
