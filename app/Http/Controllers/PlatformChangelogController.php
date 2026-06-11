<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PlatformChangelogEntry;
use Inertia\Inertia;
use Inertia\Response;

final class PlatformChangelogController extends Controller
{
    public function index(): Response
    {
        $entries = PlatformChangelogEntry::query()
            ->visibleToUsers()
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get(['id', 'published_at', 'title', 'body']);

        return Inertia::render('Settings/Changelog', [
            'entries' => $entries,
        ]);
    }
}
