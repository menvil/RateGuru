<?php

namespace App\Services\Media;

/**
 * The only way the media pipeline hands ffmpeg or ffprobe a file to read.
 *
 * A file's name says nothing about what its bytes are, and both tools pick a
 * demuxer by the bytes. A playlist (HLS, concat) saved under a .mp4 name is
 * read as a playlist, and its entries are then opened as further inputs —
 * local files or network addresses. Two whitelists close that:
 *
 *   -protocol_whitelist file   nothing is opened but a local file — no HTTP,
 *                              no other protocol a playlist could name
 *   -format_whitelist ...      only the demuxers for the formats an upload
 *                              may be: a playlist is refused before it is
 *                              parsed, so its entries are never opened
 *
 * The second one is not redundant on the hosts' ffmpeg 4.4: there, without
 * it, a playlist under a .mp4 name is read as HLS and its first entry
 * (file:///etc/hosts in the rehearsal) is stopped only by the HLS demuxer's
 * own extension check; with it, the file is refused as "Format not on
 * whitelist". The path itself goes as a `file:` URL, so a name that starts
 * like a protocol is still a file name.
 */
final class FfmpegInput
{
    /**
     * ffmpeg's names for the demuxers of the formats an upload may be:
     * MP4/MOV (and their 3GP relatives), Matroska/WebM, and GIF.
     */
    public const array DEMUXERS = ['mov,mp4,m4a,3gp,3g2,mj2', 'matroska,webm', 'gif'];

    /**
     * The input options and the input, to place where the tool takes its
     * input — every option before `-i` applies to that input.
     *
     * @return list<string>
     */
    public static function arguments(string $path): array
    {
        return [
            '-protocol_whitelist', 'file',
            '-format_whitelist', implode(',', self::DEMUXERS),
            '-i', 'file:'.$path,
        ];
    }
}
