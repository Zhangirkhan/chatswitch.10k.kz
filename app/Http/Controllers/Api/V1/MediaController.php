<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MessageMedia;
use App\Services\MessageMediaThumbnailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

final class MediaController extends Controller
{
    public function __construct(
        private readonly MessageMediaThumbnailService $thumbnailService,
    ) {}

    public function thumb(Request $request, MessageMedia $media): Response
    {
        $media->loadMissing('message.chat');

        $chat = $media->message?->chat;
        if ($chat === null) {
            abort(404);
        }

        $this->authorize('view', $chat);

        if (! $this->thumbnailService->supportsThumbnail($media)) {
            abort(404);
        }

        $thumbPath = $media->thumb_disk_path;
        if (
            ! is_string($thumbPath)
            || $thumbPath === ''
            || ! Storage::disk('local')->exists($thumbPath)
        ) {
            $thumbPath = $this->thumbnailService->generate($media);
        }

        if ($thumbPath === null || ! Storage::disk('local')->exists($thumbPath)) {
            abort(404);
        }

        $absolutePath = Storage::disk('local')->path($thumbPath);
        $mtime = @filemtime($absolutePath) ?: time();
        $size = (int) (@filesize($absolutePath) ?: 0);
        $etag = sprintf('W/"thumb-%x-%x-%x"', $media->id, $size, $mtime);
        $cacheControl = 'private, max-age=604800, stale-while-revalidate=86400';

        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', Response::HTTP_NOT_MODIFIED, [
                'ETag' => $etag,
                'Cache-Control' => $cacheControl,
                'Last-Modified' => gmdate('D, d M Y H:i:s', $mtime).' GMT',
            ]);
        }

        return response()->file($absolutePath, [
            'Content-Type' => 'image/webp',
            'Content-Disposition' => HeaderUtils::makeDisposition('inline', 'thumb.webp', 'thumb.webp'),
            'Cache-Control' => $cacheControl,
            'ETag' => $etag,
            'Last-Modified' => gmdate('D, d M Y H:i:s', $mtime).' GMT',
        ]);
    }

    public function show(Request $request, MessageMedia $media): Response
    {
        $media->loadMissing('message.chat');

        $chat = $media->message?->chat;
        if ($chat === null) {
            abort(404);
        }

        $this->authorize('view', $chat);

        $diskName = Storage::disk('local')->exists($media->disk_path) ? 'local' : 'public';
        $disk = Storage::disk($diskName);
        if (! $disk->exists($media->disk_path)) {
            abort(404);
        }

        $absolutePath = $disk->path($media->disk_path);
        $mtime = @filemtime($absolutePath) ?: time();
        $size = (int) (@filesize($absolutePath) ?: $media->file_size);
        $etag = sprintf('W/"%x-%x-%x"', $media->id, $size, $mtime);

        $cacheControl = 'private, max-age=604800, stale-while-revalidate=86400';

        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', Response::HTTP_NOT_MODIFIED, [
                'ETag' => $etag,
                'Cache-Control' => $cacheControl,
                'Last-Modified' => gmdate('D, d M Y H:i:s', $mtime).' GMT',
            ]);
        }

        $filename = $media->filename ?? basename($media->disk_path);
        $dispositionType = $request->boolean('download') ? 'attachment' : 'inline';
        $contentDisposition = HeaderUtils::makeDisposition($dispositionType, $filename, $filename);

        $mime = $media->mime_type ?: 'application/octet-stream';

        return response()->file($absolutePath, [
            'Content-Type' => $mime,
            'Content-Disposition' => $contentDisposition,
            'Cache-Control' => $cacheControl,
            'ETag' => $etag,
            'Last-Modified' => gmdate('D, d M Y H:i:s', $mtime).' GMT',
            'Accept-Ranges' => 'bytes',
        ]);
    }
}
