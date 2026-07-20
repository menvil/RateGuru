<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

it('defines a hardened reusable RateGuru deployment action', function () {
    $path = base_path('.github/actions/deploy-rateguru/action.yml');

    expect(File::exists($path))->toBeTrue();

    $source = File::get($path);
    $action = Yaml::parse($source);
    $steps = collect(data_get($action, 'runs.steps'))->keyBy('name');

    expect($action)
        ->toBeArray()
        ->and(data_get($action, 'name'))->toBe('Deploy RateGuru artifact')
        ->and(data_get($action, 'runs.using'))->toBe('composite')
        ->and(data_get($action, 'inputs.deploy-port.default'))->toBe('22')
        ->and(data_get($action, 'inputs.run-migrations.default'))->toBe('true');

    foreach ([
        'deploy-host',
        'deploy-user',
        'deploy-incoming',
        'deploy-wrapper',
        'deploy-root',
        'ssh-private-key',
        'known-hosts',
        'release-id',
        'artifact-path',
        'checksum-path',
    ] as $requiredInput) {
        expect(data_get($action, "inputs.{$requiredInput}.required"))->toBeTrue();
    }

    expect($steps->keys()->all())->toBe([
        'Validate deployment inputs',
        'Configure SSH',
        'Test SSH connection',
        'Upload artifact',
        'Deploy release',
        'Verify active release',
        'Remove temporary SSH material',
    ]);

    expect($source)
        ->toContain("release_regex='^v[0-9]+\\.[0-9]+\\.[0-9]+-[0-9]{8}-[0-9]{6}-[0-9a-f]{7,40}$'")
        ->toContain('test "${checksum_name}" = "${artifact_name}.sha256"')
        ->toContain('-o StrictHostKeyChecking=yes')
        ->toContain('-o UserKnownHostsFile="${RATEGURU_KNOWN_HOSTS_PATH}"')
        ->toContain("'sudo -n %q --release %q --artifact %q --checksum %q'")
        ->toContain('remote_command+=" --migrate"')
        ->toContain("'basename \"$(readlink -f %q)\"'")
        ->toContain("jq -r '.release'")
        ->toContain('if: ${{ always() }}');

    foreach ($steps as $step) {
        expect(data_get($step, 'shell'))->toBe('bash')
            ->and(data_get($step, 'run'))->not->toContain('${{ inputs.');
    }
});
