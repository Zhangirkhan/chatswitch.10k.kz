<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MessageMedia;
use GdImage;
use Illuminate\Support\Facades\Storage;

final class MessageMediaThumbnailService
{
    private const MAX_EDGE = 512;

    private const WEBP_QUALITY = 75;

    public function supportsThumbnail(MessageMedia $media): bool
    {
        $mime = strtolower(explode(';', (string) $media->mime_type)[0]);

        return str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml';
    }

    public function thumbApiUrl(MessageMedia $media): ?string
    {
        if (! $this->supportsThumbnail($media)) {
            return null;
        }

        return url('/api/v1/media/'.$media->id.'/thumb');
    }

    public function generate(MessageMedia $media): ?string
    {
        if (! $this->supportsThumbnail($media)) {
            return null;
        }

        if (
            is_string($media->thumb_disk_path)
            && $media->thumb_disk_path !== ''
            && Storage::disk('local')->exists($media->thumb_disk_path)
        ) {
            return $media->thumb_disk_path;
        }

        $diskName = Storage::disk('local')->exists($media->disk_path) ? 'local' : 'public';
        $disk = Storage::disk($diskName);

        if (! $disk->exists($media->disk_path)) {
            return null;
        }

        $source = $this->loadImage($disk->path($media->disk_path), (string) $media->mime_type);
        if ($source === null) {
            return null;
        }

        $resized = $this->resize($source, self::MAX_EDGE);
        imagedestroy($source);

        $thumbPath = 'whatsapp-media-thumbs/'.date('Y/m').'/'.$media->id.'.webp';

        ob_start();
        imagewebp($resized, null, self::WEBP_QUALITY);
        $webp = ob_get_clean();
        imagedestroy($resized);

        if ($webp === false || $webp === '') {
            return null;
        }

        Storage::disk('local')->put($thumbPath, $webp);
        $media->forceFill(['thumb_disk_path' => $thumbPath])->save();

        return $thumbPath;
    }

    private function loadImage(string $path, string $mimeType): ?GdImage
    {
        $mime = strtolower(explode(';', $mimeType)[0]);

        $image = match ($mime) {
            'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/gif' => @imagecreatefromgif($path),
            'image/webp' => @imagecreatefromwebp($path),
            default => false,
        };

        return $image instanceof GdImage ? $image : null;
    }

    private function resize(GdImage $source, int $maxEdge): GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);

        if ($width <= $maxEdge && $height <= $maxEdge) {
            $newWidth = $width;
            $newHeight = $height;
        } elseif ($width >= $height) {
            $newWidth = $maxEdge;
            $newHeight = (int) max(1, round($height * ($maxEdge / $width)));
        } else {
            $newHeight = $maxEdge;
            $newWidth = (int) max(1, round($width * ($maxEdge / $height)));
        }

        $destination = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($destination, false);
        imagesavealpha($destination, true);
        imagecopyresampled(
            $destination,
            $source,
            0,
            0,
            0,
            0,
            $newWidth,
            $newHeight,
            $width,
            $height,
        );

        return $destination;
    }
}
