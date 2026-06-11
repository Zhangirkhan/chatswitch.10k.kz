<?php

declare(strict_types=1);

namespace App\Services\AI;

use Symfony\Component\Process\Process;

final class AudioTranscodeService
{
    /** @var list<string> */
    private const TRANSCODE_MIMES = [
        'audio/mp4',
        'audio/x-m4a',
        'audio/aac',
        'audio/x-caf',
        'audio/amr',
        'video/mp4',
        'video/webm',
    ];

    public function needsTranscode(string $mimeType): bool
    {
        return in_array(strtolower(trim($mimeType)), self::TRANSCODE_MIMES, true);
    }

    public function transcodeToWebm(string $inputPath): ?string
    {
        if (! is_readable($inputPath)) {
            return null;
        }

        $dir = storage_path('app/tmp');
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return null;
        }

        $output = $dir.'/'.uniqid('dictation-', true).'.webm';

        $process = new Process([
            'ffmpeg',
            '-nostdin',
            '-hide_banner',
            '-loglevel', 'error',
            '-y',
            '-i', $inputPath,
            '-vn',
            '-c:a', 'libopus',
            '-b:a', '32k',
            '-ac', '1',
            '-ar', '16000',
            $output,
        ]);
        $process->setTimeout(120);

        try {
            $process->mustRun();
        } catch (\Throwable) {
            @unlink($output);

            return null;
        }

        if (! is_readable($output) || filesize($output) === 0) {
            @unlink($output);

            return null;
        }

        return $output;
    }
}
