# Browser Smoke Tests

RateGuru uses Pest Browser Testing for browser smoke coverage.

Run the browser smoke suite with:

```bash
composer test:browser
```

Local prerequisites:

- Composer dev dependencies installed.
- NPM dependencies installed.
- Playwright Chromium installed with `npx playwright install chromium`.
- The command starts its own local Laravel HTTP server; no separate
  `php artisan serve` process is required.
- Browser tests use the normal Laravel testing database configuration from
  `phpunit.xml` and reset state with `RefreshDatabase`.

The suite is intentionally separate from `composer test`, which runs only the
Unit and Feature testsuites, so regular unit, feature, and Livewire tests stay
fast.

Phase 38 browser tests cover critical flows only:

- feed page load;
- authentication;
- upload modal open;
- post drawer open;
- voting;
- comments;
- report modal open;
- admin access smoke checks.

They do not create screenshot baselines, run pixel comparisons, or perform
visual regression checks. They also avoid external services, native file
pickers, broad admin resource CRUD, and exhaustive validation coverage.
