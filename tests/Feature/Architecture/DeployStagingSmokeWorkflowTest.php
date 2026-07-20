<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

it('defines a manually triggered staging smoke deployment', function () {
    $path = base_path('.github/workflows/deploy-staging-smoke.yml');

    expect(File::exists($path))->toBeTrue();

    $source = File::get($path);
    $workflow = Yaml::parse($source);

    expect($workflow)
        ->toBeArray()
        ->and(data_get($workflow, 'name'))->toBe('Deploy staging smoke test')
        ->and(data_get($workflow, 'on'))->toHaveKey('workflow_dispatch')
        ->and(data_get($workflow, 'permissions.contents'))->toBe('read')
        ->and(data_get($workflow, 'concurrency.group'))->toBe('rateguru-staging-deployment')
        ->and(data_get($workflow, 'concurrency.cancel-in-progress'))->toBeFalse()
        ->and(data_get($workflow, 'jobs.deploy.environment'))->toBe('staging')
        ->and(data_get($workflow, 'jobs.deploy.timeout-minutes'))->toBe(10);

    expect($source)
        ->toContain('DEPLOY_SSH_KEY: ${{ secrets.DEPLOY_SSH_KEY }}')
        ->toContain('DEPLOY_KNOWN_HOSTS: ${{ secrets.DEPLOY_KNOWN_HOSTS }}')
        ->toContain('StrictHostKeyChecking=yes')
        ->toContain('sha256sum "${artifact_name}"')
        ->toContain('test "${actual_release}" = "${expected_release}"');
});
