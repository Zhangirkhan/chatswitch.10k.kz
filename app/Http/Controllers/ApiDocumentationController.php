<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;

final class ApiDocumentationController extends Controller
{
    private const SPEC_PATH = 'openapi/mobile-v1.yaml';

    public function swagger(): Response
    {
        return response()->view('docs.swagger', [
            'title' => 'Mobile API v1 — Swagger',
            'openApiUrl' => url('/docs/api/openapi.yaml'),
        ]);
    }

    public function openApiYaml(): Response
    {
        $path = base_path(self::SPEC_PATH);
        if (! is_readable($path)) {
            abort(404);
        }

        return response((string) file_get_contents($path), 200, [
            'Content-Type' => 'application/yaml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
