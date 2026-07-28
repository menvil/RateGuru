<?php

use Illuminate\Support\Facades\File;

function socialSmokeScratchDir(): string
{
    $directory = sys_get_temp_dir().'/social-smoke-'.uniqid('', true);

    mkdir($directory.'/bin', 0o700, true);

    return $directory;
}

function socialSmokeFakeCurl(string $directory, string $log): void
{
    $script = <<<'BASH'
#!/usr/bin/env bash
set -Eeuo pipefail

output=""
url=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --output)
            output="$2"
            shift 2
            ;;
        --write-out|--user|--user-agent|--max-time)
            shift 2
            ;;
        --silent|--show-error|--location)
            shift
            ;;
        *)
            url="$1"
            shift
            ;;
    esac
done

printf '%s\n' "$*" >> "${CURL_LOG}"
printf '%s\n' "${url}" >> "${CURL_LOG}"

if [[ "${url}" == *"/social.jpg" ]]; then
    printf 'fake jpeg' > "${output}"
    printf '200\nimage/jpeg'
    exit 0
fi

cat > "${output}" <<'HTML'
<html><head>
<link rel="canonical" href="https://rateguru.test/posts/42">
<meta property="og:url" content="https://rateguru.test/posts/42">
<meta property="og:title" content="Social smoke">
<meta property="og:image" content="https://rateguru.test/social.jpg">
</head></html>
HTML
printf '200'
BASH;

    File::put($directory.'/bin/curl', $script);
    chmod($directory.'/bin/curl', 0o700);
    File::put($log, '');
}

function runSocialSmokeScript(string $directory, array $environment = []): array
{
    $command = '';

    foreach ($environment as $key => $value) {
        $command .= $key.'='.escapeshellarg($value).' ';
    }

    $command .= 'PATH='.escapeshellarg($directory.'/bin:'.getenv('PATH')).' ';
    $command .= 'bash '.escapeshellarg(base_path('infrastructure/scripts/social-preview-smoke'));

    exec($command.' 2>&1', $output, $exitCode);

    return [$exitCode, implode("\n", $output)];
}

it('ships a syntactically valid crawler smoke script', function () {
    $path = base_path('infrastructure/scripts/social-preview-smoke');

    expect(File::exists($path))->toBeTrue();

    exec('bash -n '.escapeshellarg($path).' 2>&1', $output, $exitCode);

    expect($exitCode)->toBe(0, implode("\n", $output))
        ->and(File::get($path))
        ->toContain('facebookexternalhit/1.1')
        ->toContain('property="og:url"')
        ->toContain('property="og:image"')
        ->toContain('image/jpeg')
        ->toContain('image/png');
});

it('checks rendered metadata and its image through curl', function () {
    $directory = socialSmokeScratchDir();
    $log = $directory.'/curl.log';
    socialSmokeFakeCurl($directory, $log);

    try {
        [$exitCode, $output] = runSocialSmokeScript($directory, [
            'CURL_LOG' => $log,
            'RATEGURU_SOCIAL_SMOKE_URL' => 'https://rateguru.test/posts/42',
            'RATEGURU_SOCIAL_SMOKE_BASIC_AUTH' => 'crawler:secret',
        ]);

        expect($exitCode)->toBe(0, $output)
            ->and($output)->toContain('Social preview smoke test passed')
            ->and(File::get($log))->toContain('https://rateguru.test/posts/42')
            ->toContain('https://rateguru.test/social.jpg');
    } finally {
        File::deleteDirectory($directory);
    }
});
