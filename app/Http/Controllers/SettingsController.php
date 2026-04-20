<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SettingsController extends Controller
{
    public function index(): Response
    {
        $settings = SystemSetting::all()->pluck('value', 'key');

        return Inertia::render('Settings/System', [
            'settings' => $settings,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'settings' => 'required|array',
            'settings.*' => 'nullable|string|max:2000',
        ]);

        foreach ($validated['settings'] as $key => $value) {
            SystemSetting::setValue($key, $value);
        }

        return response()->json(['success' => true]);
    }
}
