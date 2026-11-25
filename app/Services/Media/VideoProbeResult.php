<?php

namespace App\Services\Media;

/**
 * What ffprobe found in a file, reduced to what the pipeline decides on.
 * Width and height are the picture as shown — after the rotation a phone
 * records instead of turning the pixels — so a portrait clip filmed sideways
 * reads as portrait.
 */
final readonly class VideoProbeResult
{
    public function __construct(
        public string $formatName,
        public ?int $durationMs,
        public int $width,
        public int $height,
        public int $rotation,
        public ?float $frameRate,
        public ?string $videoCodec,
        public ?string $audioCodec,
        public int $videoStreamCount,
        public int $audioStreamCount,
        public int $otherStreamCount,
    ) {}

    /**
     * From ffprobe's `-show_format -show_streams -of json` output.
     *
     * A cover picture embedded in an MP4 is a video stream to ffprobe, marked
     * attached_pic; it is not the video and counts among the other streams,
     * with subtitles, timecode and the data tracks phones add.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromFfprobe(array $data): self
    {
        $format = is_array($data['format'] ?? null) ? $data['format'] : [];
        $streams = array_values(array_filter(is_array($data['streams'] ?? null) ? $data['streams'] : [], 'is_array'));

        $video = array_values(array_filter(
            $streams,
            fn (array $stream): bool => ($stream['codec_type'] ?? null) === 'video'
                && (int) (is_array($stream['disposition'] ?? null) ? ($stream['disposition']['attached_pic'] ?? 0) : 0) !== 1,
        ));
        $audio = array_values(array_filter($streams, fn (array $stream): bool => ($stream['codec_type'] ?? null) === 'audio'));
        $first = $video[0] ?? [];

        $rotation = self::rotation($first);
        $codedWidth = (int) ($first['width'] ?? 0);
        $codedHeight = (int) ($first['height'] ?? 0);
        $sideways = $rotation === 90 || $rotation === 270;

        return new self(
            formatName: (string) ($format['format_name'] ?? ''),
            durationMs: self::milliseconds($format['duration'] ?? null) ?? self::milliseconds($first['duration'] ?? null),
            width: $sideways ? $codedHeight : $codedWidth,
            height: $sideways ? $codedWidth : $codedHeight,
            rotation: $rotation,
            frameRate: self::rate($first['avg_frame_rate'] ?? null) ?? self::rate($first['r_frame_rate'] ?? null),
            videoCodec: isset($first['codec_name']) ? (string) $first['codec_name'] : null,
            audioCodec: isset($audio[0]['codec_name']) ? (string) $audio[0]['codec_name'] : null,
            videoStreamCount: count($video),
            audioStreamCount: count($audio),
            otherStreamCount: count($streams) - count($video) - count($audio),
        );
    }

    public function hasAudio(): bool
    {
        return $this->audioStreamCount > 0;
    }

    /**
     * Clockwise degrees in 0, 90, 180, 270. ffmpeg 5 and later report a
     * display matrix in side data (a negative angle for a clockwise turn);
     * ffmpeg 4.4, which the hosts run, reports a `rotate` tag. Only the
     * quarter turns matter for the picture's shape.
     *
     * @param  array<string, mixed>  $stream
     */
    private static function rotation(array $stream): int
    {
        $degrees = null;

        foreach (is_array($stream['side_data_list'] ?? null) ? $stream['side_data_list'] : [] as $sideData) {
            if (is_array($sideData) && is_numeric($sideData['rotation'] ?? null)) {
                $degrees = -(int) round((float) $sideData['rotation']);
            }
        }

        if ($degrees === null && is_array($stream['tags'] ?? null) && is_numeric($stream['tags']['rotate'] ?? null)) {
            $degrees = (int) $stream['tags']['rotate'];
        }

        return ((((int) round(($degrees ?? 0) / 90)) * 90) % 360 + 360) % 360;
    }

    private static function milliseconds(mixed $seconds): ?int
    {
        return is_numeric($seconds) && (float) $seconds >= 0 ? (int) round((float) $seconds * 1000) : null;
    }

    /** "30000/1001" -> 29.97; "0/0", the answer for a still or an unknown rate, -> null. */
    private static function rate(mixed $fraction): ?float
    {
        if (! is_string($fraction) || preg_match('#^(\d+)/(\d+)$#', $fraction, $matches) !== 1 || (int) $matches[2] === 0 || (int) $matches[1] === 0) {
            return null;
        }

        return round((int) $matches[1] / (int) $matches[2], 3);
    }
}
