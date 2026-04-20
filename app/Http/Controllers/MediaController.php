<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MessageMedia;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class MediaController extends Controller
{
    public function show(MessageMedia $media): StreamedResponse
    {
        if (! Storage::disk('local')->exists($media->disk_path)) {
            abort(404);
        }

        return Storage::disk('local')->download(
            $media->disk_path,
            $media->filename,
            ['Content-Type' => $media->mime_type],
        );
    }
}
