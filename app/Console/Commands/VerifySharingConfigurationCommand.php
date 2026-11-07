<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Support\Seo\PostOpenGraph;
use App\Support\Urls\PostUrl;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

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

        // The disk media is actually stored on, which need not be the one
        // named `public`: a deployment may point MEDIA_PUBLIC_DISK elsewhere.
        // A URL is built as a real post image's is: by the disk, then made
        // absolute by Open Graph. A disk with no url, or only a path, resolves
        // against APP_URL; a protocol-relative one takes APP_URL's scheme; a
        // driver that builds its own URLs, a bucket's, keeps its own host.
        $mediaDisk = (string) config('media.disks.public', 'public');

        try {
            $publicImageUrl = $openGraph->absoluteUrl(Storage::disk($mediaDisk)->url('sharing-check.jpg'));
        } catch (Throwable $exception) {
            // Its first line names the cause, an unconfigured disk or a driver
            // with no URLs, without whatever a driver appends below it.
            $publicImageUrl = null;
            $errors[] = "Public image URLs cannot be built from the [{$mediaDisk}] disk: ".strtok($exception->getMessage(), "\n");
        }

        if ($publicImageUrl !== null) {
            if ($expectedHost !== '' && strtolower((string) parse_url($publicImageUrl, PHP_URL_HOST)) !== $expectedHost) {
                $errors[] = "Public image URL host must match [{$expectedHost}].";
            }

            // Checked here and not only through the probe below. The probe post
            // has no image asset, so its Open Graph image is the APP_URL-based
            // fallback and never touches this disk — an http:// disk on the right
            // host would pass every other check in this command and then serve
            // every real post image to Facebook over plain HTTP.
            if (strtolower((string) parse_url($publicImageUrl, PHP_URL_SCHEME)) !== 'https') {
                $errors[] = 'Public image URL must use HTTPS.';
            }
        }

        if (! extension_loaded('gd')) {
            $errors[] = 'The GD extension is required to generate Open Graph images.';
        }

        if ($errors === []) {
            $post = new Post([
                'title' => 'Sharing deployment check',
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
