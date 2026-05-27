# Browser Smoke Tests

RateGuru uses Pest Browser Testing for browser smoke coverage.

Run the browser smoke suite with:

```bash
composer test:browser
```

The suite is intentionally separate from `composer test` so regular unit,
feature, and Livewire tests stay fast.

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
visual regression checks.
