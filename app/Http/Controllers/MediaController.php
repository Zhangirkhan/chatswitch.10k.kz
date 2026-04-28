<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MessageMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class MediaController extends Controller
{
    public function show(Request $request, MessageMedia $media): StreamedResponse
    {
        $media->loadMissing('message.chat');

        $chat = $media->message?->chat;
        if (! $chat) {
            abort(404);
        }

        $this->authorize('view', $chat);

        $disk = Storage::disk('local');
        if (! $disk->exists($media->disk_path)) {
            abort(404);
        }

        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return $disk->response(
            $media->disk_path,
            $media->filename,
            [
                'Content-Type' => $media->mime_type ?: 'application/octet-stream',
                'Cache-Control' => 'private, max-age=86400',
            ],
            $disposition,
        );
    }
}
