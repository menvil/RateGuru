<?php

namespace App\Support\Media;

use App\Enums\MediaType;
use App\Enums\MediaVisibility;
use App\Services\Media\Exceptions\VideoIngestException;
use App\Services\Media\MediaLocation;
use App\Services\Media\MediaPathGenerator;
use App\Services\Media\MediaStorage;
use App\Services\Media\MediaStoreRequest;
use App\Services\Media\StoredMedia;
use App\Services\Media\StoredVideo;
use App\Services\Media\VideoIngestPolicy;
use App\Services\Media\VideoProbe;
use Illuminate\Http\UploadedFile;

/**
 * Accepts an uploaded video or animated GIF the way ImageUploadStorer
 * accepts a picture: the size limit before any tool opens it, then ffprobe
 * and the upload policy on what the file really is, whatever its name says.
 * Only then is the file stored, as it arrived, on the private disk.
 */
final class VideoUploadStorer
{
    /** The container ffprobe names, and the extension and type the original is stored under. */
    private const array CONTAINERS = [
        'gif' => ['gif', 'image/gif'],
        'matroska,webm' => ['webm', 'video/webm'],
        'mov,mp4,m4a,3gp,3g2,mj2' => ['mp4', 'video/mp4'],
    ];

    public function __construct(
        private readonly VideoProbe $probe,
        private readonly MediaStorage $storage,
        private readonly MediaPathGenerator $paths,
    ) {}

    /**
     * @throws VideoIngestException when the policy refuses the file
     */
    public function store(UploadedFile $file, int $ownerUserId): StoredVideo
    {
        $policy = VideoIngestPolicy::fromConfig();
        $bytes = (int) $file->getSize();
        $local = (string) $file->getRealPath();

        $policy->assertWithinByteLimit($bytes);

        $probe = $this->probe->probe($local);
        $policy->assertAcceptable($probe, $bytes);

        [$extension, $mimeType] = self::CONTAINERS[$probe->formatName];

        $request = MediaStoreRequest::forVideoOriginal($ownerUserId);
        $path = $this->paths->generate($request, $extension);

        $this->storage->putFile(new MediaLocation($request->disk, $path), $local, MediaVisibility::Private);

        return new StoredVideo(
            media: new StoredMedia(
                disk: $request->disk,
                path: $path,
                originalFilename: $file->getClientOriginalName(),
                mimeType: $mimeType,
                extension: $extension,
                byteSize: $bytes,
                width: $probe->width,
                height: $probe->height,
            ),
            probe: $probe,
            mediaType: $probe->formatName === 'gif' ? MediaType::Animation : MediaType::Video,
        );
    }
}
