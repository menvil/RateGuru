<?php

namespace App\Services\Images;

use App\Models\Post;
use GdImage;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class OpenGraphImageGenerator
{
    public function generate(Post $post): ?string
    {
        $sourcePath = trim((string) $post->image_path);

        if ($sourcePath === '') {
            return null;
        }

        $diskName = (string) config('rateguru.images.disk', 'public');
        $disk = Storage::disk($diskName);

        if (! $disk->exists($sourcePath)) {
            throw new RuntimeException("Post image [{$sourcePath}] does not exist on disk [{$diskName}].");
        }

        $sourceContents = $disk->get($sourcePath);
        $source = @imagecreatefromstring($sourceContents);

        if (! $source instanceof GdImage) {
            throw new RuntimeException("Post image [{$sourcePath}] cannot be decoded.");
        }

        try {
            $target = $this->cropToOpenGraphSize($source);

            try {
                $encoded = $this->encodeWithinLimit($target);
            } finally {
                imagedestroy($target);
            }
        } finally {
            imagedestroy($source);
        }

        $targetPath = "posts/{$post->user_id}/og/{$post->id}.jpg";

        if (! $disk->put($targetPath, $encoded, ['visibility' => 'public'])) {
            throw new RuntimeException("Could not store generated Open Graph image [{$targetPath}].");
        }

        return $targetPath;
    }

    private function cropToOpenGraphSize(GdImage $source): GdImage
    {
        $targetWidth = (int) config('share.open_graph.width', 1200);
        $targetHeight = (int) config('share.open_graph.height', 630);
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $targetRatio = $targetWidth / $targetHeight;
        $sourceRatio = $sourceWidth / $sourceHeight;

        if ($sourceRatio > $targetRatio) {
            $cropHeight = $sourceHeight;
            $cropWidth = (int) round($sourceHeight * $targetRatio);
            $sourceX = (int) floor(($sourceWidth - $cropWidth) / 2);
            $sourceY = 0;
        } else {
            $cropWidth = $sourceWidth;
            $cropHeight = (int) round($sourceWidth / $targetRatio);
            $sourceX = 0;
            $sourceY = (int) floor(($sourceHeight - $cropHeight) / 2);
        }

        $target = imagecreatetruecolor($targetWidth, $targetHeight);

        if (! $target instanceof GdImage) {
            throw new RuntimeException('Could not allocate the Open Graph image canvas.');
        }

        $background = imagecolorallocate($target, 10, 10, 15);
        imagefill($target, 0, 0, $background);

        imagecopyresampled(
            $target,
            $source,
            0,
            0,
            $sourceX,
            $sourceY,
            $targetWidth,
            $targetHeight,
            $cropWidth,
            $cropHeight,
        );

        return $target;
    }

    private function encodeWithinLimit(GdImage $image): string
    {
        $quality = (int) config('share.open_graph.jpeg_quality', 82);
        $minimumQuality = (int) config('share.open_graph.minimum_jpeg_quality', 42);
        $maximumBytes = (int) config('share.open_graph.max_kilobytes', 700) * 1024;
        $encoded = '';

        do {
            ob_start();
            $encodedSuccessfully = imagejpeg($image, null, $quality);
            $buffer = ob_get_clean();

            if (! $encodedSuccessfully) {
                throw new RuntimeException('Could not encode the Open Graph image.');
            }

            $encoded = $buffer;
            $quality -= 8;
        } while (strlen($encoded) > $maximumBytes && $quality >= $minimumQuality);

        if (strlen($encoded) > $maximumBytes) {
            throw new RuntimeException('Generated Open Graph image exceeds the configured size limit.');
        }

        return $encoded;
    }
}
