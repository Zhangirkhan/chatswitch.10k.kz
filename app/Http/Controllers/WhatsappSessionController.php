<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\WhatsappSession;
use App\Services\WhatsappService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class WhatsappSessionController extends Controller
{
    public function __construct(
        private readonly WhatsappService $whatsappService,
    ) {}

    public function index(): Response
    {
        $sessions = WhatsappSession::orderBy('created_at')->get();

        return Inertia::render('Settings/Connections', [
            'sessions' => $sessions,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'session_name' => 'required|string|max:100|unique:whatsapp_sessions,session_name',
            'display_name' => 'nullable|string|max:100',
        ]);

        $session = WhatsappSession::create([
            'session_name' => $request->input('session_name'),
            'display_name' => $request->input('display_name'),
            'status' => 'disconnected',
        ]);

        return response()->json(['success' => true, 'session' => $session]);
    }

    public function initialize(WhatsappSession $session): JsonResponse
    {
        $session->update(['status' => 'connecting']);

        $result = $this->whatsappService->initializeSession($session->session_name);

        return response()->json($result);
    }

    public function qr(WhatsappSession $session): JsonResponse
    {
        $result = $this->whatsappService->getSessionQR($session->session_name);

        return response()->json($result);
    }

    public function status(WhatsappSession $session): JsonResponse
    {
        $result = $this->whatsappService->getSessionStatus($session->session_name);

        if (! empty($result['isReady']) && $session->status !== 'connected') {
            $session->update(['status' => 'connected', 'connected_at' => now()]);
        }

        return response()->json(array_merge($result, ['session' => $session->fresh()]));
    }

    public function logout(WhatsappSession $session): JsonResponse
    {
        $this->whatsappService->logoutSession($session->session_name);

        $session->update([
            'status' => 'disconnected',
            'disconnected_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    public function destroy(WhatsappSession $session): JsonResponse
    {
        $this->whatsappService->destroySession($session->session_name);
        $session->delete();

        return response()->json(['success' => true]);
    }
}
