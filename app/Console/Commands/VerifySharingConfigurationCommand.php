<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Support\Seo\PostOpenGraph;
use App\Support\Urls\PostUrl;
use Illuminate\Console\Command;

final class VerifySharingConfigurationCommand extends Command
{
    protected $signature = 'rateguru:sharing:verify
        {--expected-host= : Public hostname declared for this deployment target}';

    protected $description = 'Verify canonical and Open Graph URLs for social sharing.';

    public function handle(PostUrl $postUrl, PostOpenGraph $openGraph): int
    {
        $expectedHost = strtolower(trim((string) $this->option('expected-host')));
        $appUrl = trim((string) config('app.url'));
        $errors = [];

        if ($expectedHost === '') {
            $errors[] = '--expected-host must be configured.';
        }

        if ($appUrl === '') {
            $errors[] = 'APP_URL must be configured.';
        } else {
            if (parse_url($appUrl, PHP_URL_SCHEME) !== 'https') {
                $errors[] = 'APP_URL must use HTTPS.';
            }

            if ($expectedHost !== '' && strtolower((string) parse_url($appUrl, PHP_URL_HOST)) !== $expectedHost) {
                $errors[] = "APP_URL host must match [{$expectedHost}].";
            }
        }

        $publicImageUrl = trim((string) config('filesystems.disks.public.url'));

        if ($expectedHost !== '' && strtolower((string) parse_url($publicImageUrl, PHP_URL_HOST)) !== $expectedHost) {
            $errors[] = "Public image URL host must match [{$expectedHost}].";
        }

        if (! extension_loaded('gd')) {
            $errors[] = 'The GD extension is required to generate Open Graph images.';
        }

        if ($errors === []) {
            $post = new Post([
                'title' => 'Sharing deployment check',
                'image_path' => null,
                'image_url' => null,
                'og_image_path' => null,
            ]);
            $post->id = 1;

            $canonicalUrl = $postUrl->canonical($post);
            $imageUrl = $openGraph->image($post)->url;

            foreach (['Canonical URL' => $canonicalUrl, 'Open Graph image URL' => $imageUrl] as $label => $url) {
                if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
                    $errors[] = "{$label} must use HTTPS.";
                }

                if (strtolower((string) parse_url($url, PHP_URL_HOST)) !== $expectedHost) {
                    $errors[] = "{$label} host must match [{$expectedHost}].";
                }
            }
        }

        if ($errors !== []) {
            foreach (array_unique($errors) as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $this->components->info("Sharing configuration is valid for [{$expectedHost}].");

        return self::SUCCESS;
    }
}
