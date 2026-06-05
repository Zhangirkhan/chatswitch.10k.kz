<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\Message;
use App\Models\WhatsappSession;
use App\Services\ChatService;
use App\Services\DemoWhatsappSessionSimulator;
use App\Services\WhatsappService;
use App\Services\WhatsappSessionLimitService;
use App\Support\WhatsappSessionStatusHints;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class WhatsappSessionController extends Controller
{
    public function __construct(
        private readonly WhatsappService $whatsappService,
        private readonly ChatService $chatService,
        private readonly WhatsappSessionLimitService $sessionLimits,
        private readonly DemoWhatsappSessionSimulator $demoSessions,
    ) {}

    public function index(): Response
    {
        $sessions = WhatsappSession::orderBy('created_at')->get();

        if ($this->demoSessions->isDemoTenant()) {
            $sessions->each(fn (WhatsappSession $session) => $this->demoSessions->markConnected($session));
            $sessions = WhatsappSession::orderBy('created_at')->get();
        }

        return Inertia::render('Settings/Connections', [
            'sessions' => $sessions,
            'whatsappServiceReachable' => $this->demoSessions->isDemoTenant() ? true : null,
            'sessionLimits' => $this->sessionLimits->payload(),
        ]);
    }

    public function bootstrap(): JsonResponse
    {
        if ($this->demoSessions->isDemoTenant()) {
            $sessions = WhatsappSession::orderBy('created_at')->get();
            $sessions->each(fn (WhatsappSession $session) => $this->demoSessions->markConnected($session));

            return response()->json([
                'whatsappServiceReachable' => true,
                'sessions' => $this->formatSessionsForApi(
                    WhatsappSession::orderBy('created_at')->get(),
                ),
            ]);
        }

        $reachable = $this->whatsappService->healthReachable();
        $sessions = WhatsappSession::orderBy('created_at')->get();

        if ($reachable) {
            $this->reconcileSessionsWithMicroservice($sessions);
            $sessions = WhatsappSession::orderBy('created_at')->get();
        }

        return response()->json([
            'whatsappServiceReachable' => $reachable,
            'sessions' => $this->formatSessionsForApi($sessions),
        ]);
    }

    /**
     * @param  Collection<int, WhatsappSession>  $sessions
     * @return list<array<string, mixed>>
     */
    private function formatSessionsForApi(Collection $sessions): array
    {
        return $sessions
            ->map(fn (WhatsappSession $session): array => $session->toConnectionArray())
            ->values()
            ->all();
    }

    /**
     * Приводит статусы в БД в соответствие с реальным состоянием клиентов в whatsapp-service.
     * Если сессия есть в БД, но Node о ней не знает — пробуем переинициализировать.
     *
     * @param  Collection<int, WhatsappSession>  $sessions
     */
    private function reconcileSessionsWithMicroservice(Collection $sessions): void
    {
        foreach ($sessions as $session) {
            // Пользователь сам разлогинился — ничего не поднимаем, пока он явно
            // не нажмёт «Подключить». Иначе сразу после logout watchdog будет
            // заново инициализировать сессию, которую он только что выключил.
            if ($session->desired_state === WhatsappSession::DESIRED_LOGGED_OUT) {
                continue;
            }

            $r = $this->whatsappService->getSessionStatus($session->session_name);

            // Node ничего не знает об этой сессии (пустой ответ или ошибка) — пропускаем
            if (empty($r['success'])) {
                // desired_state=active ⇒ стараемся поднять: пользователь хочет, чтобы
                // номер работал. Это же закрывает кейс «Node только что рестартанули».
                $this->whatsappService->initializeSession($session->session_name, (int) $session->company_id);
                $session->forceFill(['status' => 'connecting'])->save();

                continue;
            }

            $isReady = ! empty($r['isReady']);
            $hasQr = ! empty($r['hasQR']);
            $isInitializing = ! empty($r['isInitializing']);

            if ($isReady) {
                $session->forceFill([
                    'status' => 'connected',
                    'connected_at' => $session->connected_at ?? now(),
                    'qr_required_at' => null,
                ])->save();

                continue;
            }

            if ($hasQr) {
                // В БД могло быть «connected» от прошлого READY, а сейчас страница
                // WA Web откатилась к QR (multi-device bounce). Не врать пользователю.
                $wasConnected = $session->status === 'connected';
                $session->forceFill([
                    'status' => 'qr_pending',
                    'disconnected_at' => $wasConnected ? now() : $session->disconnected_at,
                    'qr_required_at' => $session->qr_required_at ?? now(),
                ])->save();

                continue;
            }

            if (! $isReady && ! $isInitializing && $session->status === 'connected') {
                $session->forceFill([
                    'status' => 'disconnected',
                    'disconnected_at' => now(),
                ])->save();
            }
        }
    }

    public function store(Request $request): JsonResponse
    {
        if (! $this->whatsappService->healthReachable()) {
            return response()->json([
                'message' => 'Сервис WhatsApp недоступен. Убедитесь, что whatsapp-service запущен и в .env задан корректный WHATSAPP_SERVICE_URL.',
            ], 503);
        }

        $denyReason = $this->sessionLimits->denyReason();
        if ($denyReason !== null) {
            return response()->json(['message' => $denyReason], 422);
        }

        $request->validate([
            'session_name' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('whatsapp_sessions', 'session_name')
                    ->where('company_id', app(TenantContext::class)->companyId()),
            ],
            'display_name' => 'nullable|string|max:100',
        ]);

        $sessionName = $request->input('session_name') ?: $this->generateSessionName();
        $displayName = $request->input('display_name') ?: $this->generateDisplayName();

        return DB::transaction(function () use ($sessionName, $displayName): JsonResponse {
            $session = WhatsappSession::create([
                'session_name' => $sessionName,
                'display_name' => $displayName,
                'display_color' => $this->defaultDisplayColorForNewSession(),
                'status' => 'connecting',
                'desired_state' => WhatsappSession::DESIRED_ACTIVE,
            ]);

            $init = $this->whatsappService->initializeSession($session->session_name, (int) $session->company_id);

            if (! $this->whatsappService->initializeAccepted($init)) {
                $session->delete();

                return response()->json([
                    'message' => is_string($init['error'] ?? null)
                        ? $init['error']
                        : 'Сервис WhatsApp не принял инициализацию сессии. Проверьте WHATSAPP_SERVICE_TOKEN и логи whatsapp-service.',
                ], 503);
            }

            return response()->json(['success' => true, 'session' => $session->fresh()]);
        });
    }

    private function generateSessionName(): string
    {
        do {
            $name = 'wa-'.strtolower(substr(bin2hex(random_bytes(4)), 0, 8));
        } while (WhatsappSession::query()->where('session_name', $name)->exists());

        return $name;
    }

    private function generateDisplayName(): string
    {
        $count = WhatsappSession::count() + 1;

        return 'WhatsApp #'.$count;
    }

    private function defaultDisplayColorForNewSession(): string
    {
        $palette = [
            '#01b964',
            '#f5c518',
            '#3b82f6',
            '#f97316',
            '#a855f7',
            '#ec4899',
            '#14b8a6',
            '#ef4444',
        ];
        $index = WhatsappSession::count() % count($palette);

        return $palette[$index];
    }

    public function update(Request $request, WhatsappSession $session): JsonResponse
    {
        $request->validate([
            'display_name' => 'required|string|max:100',
            'display_color' => 'nullable|string|max:20',
        ]);

        $session->update([
            'display_name' => $request->input('display_name'),
            'display_color' => $request->input('display_color'),
        ]);

        return response()->json(['success' => true, 'session' => $session->fresh()]);
    }

    public function initialize(WhatsappSession $session): JsonResponse
    {
        if ($this->demoSessions->isDemoTenant()) {
            return response()->json($this->demoSessions->simulatedStatusPayload($session));
        }

        if (! $this->whatsappService->healthReachable()) {
            return response()->json([
                'message' => 'Сервис WhatsApp недоступен. Проверьте WHATSAPP_SERVICE_URL и запуск whatsapp-service.',
            ], 503);
        }

        // Явное «Подключить» от пользователя ⇒ снова считаем подключение
        // желаемым. Watchdog с этого момента будет автоматически поднимать его.
        $session->update([
            'status' => 'connecting',
            'desired_state' => WhatsappSession::DESIRED_ACTIVE,
        ]);

        $result = $this->whatsappService->initializeSession($session->session_name, (int) $session->company_id);

        return response()->json($result);
    }

    public function qr(WhatsappSession $session): JsonResponse
    {
        if ($this->demoSessions->isDemoTenant()) {
            return response()->json($this->demoSessions->simulatedStatusPayload($session));
        }

        $result = $this->whatsappService->getSessionQR($session->session_name);

        return response()->json($result);
    }

    public function diagnostics(WhatsappSession $session): JsonResponse
    {
        $this->authorize('manage', $session);

        $health = $this->whatsappService->healthPing();
        $timed = $this->whatsappService->getSessionStatusTimed($session->session_name);

        $session->loadCount(['chats', 'messages']);

        return response()->json([
            'session' => [
                'id' => $session->id,
                'session_name' => $session->session_name,
                'display_name' => $session->display_name,
                'phone_number' => $session->phone_number,
                'wa_name' => $session->wa_name,
                'wa_platform' => $session->wa_platform,
                'status' => $session->status,
                'status_hint' => WhatsappSessionStatusHints::forSession($session),
                'is_active' => (bool) $session->is_active,
                'connected_at' => $session->connected_at?->toIso8601String(),
                'disconnected_at' => $session->disconnected_at?->toIso8601String(),
                'last_disconnect_reason' => $session->last_disconnect_reason,
                'last_auth_failure_message' => $session->last_auth_failure_message,
                'qr_required_at' => $session->qr_required_at?->toIso8601String(),
                'created_at' => $session->created_at?->toIso8601String(),
                'updated_at' => $session->updated_at?->toIso8601String(),
                'chats_count' => $session->chats_count,
                'messages_count' => $session->messages_count,
            ],
            'whatsapp_service' => [
                'reachable' => $health['ok'],
                'health_latency_ms' => $health['latency_ms'],
                'health_body' => $health['body'],
                'session_status_latency_ms' => $timed['latency_ms'],
                'node_status' => $timed['result'],
            ],
        ]);
    }

    public function status(WhatsappSession $session): JsonResponse
    {
        if ($this->demoSessions->isDemoTenant()) {
            return response()->json($this->demoSessions->simulatedStatusPayload($session));
        }

        $result = $this->whatsappService->getSessionStatus($session->session_name);

        if ($result !== []) {
            $isReady = ! empty($result['isReady']);
            $hasQr = ! empty($result['hasQR']);
            $isInitializing = ! empty($result['isInitializing']);

            if ($isReady) {
                $session->forceFill([
                    'status' => 'connected',
                    'connected_at' => $session->connected_at ?? now(),
                    'qr_required_at' => null,
                ])->save();
            } elseif ($hasQr) {
                $wasConnected = $session->status === 'connected';
                $session->forceFill([
                    'status' => 'qr_pending',
                    'disconnected_at' => $wasConnected ? now() : $session->disconnected_at,
                    'qr_required_at' => $session->qr_required_at ?? now(),
                ])->save();
            } elseif (! $isReady && ! $hasQr && ! $isInitializing && $session->status === 'connected') {
                $session->forceFill([
                    'status' => 'disconnected',
                    'disconnected_at' => now(),
                ])->save();
            }
        }

        return response()->json(array_merge($result, ['session' => $session->fresh()?->toConnectionArray()]));
    }

    /**
     * Активная проверка живого подключения. Идёт в Node → Puppeteer → WhatsApp Web,
     * читает реальное состояние клиента. Если WA сказал, что не CONNECTED — фиксируем в БД
     * соответствующий статус, чтобы UI перестал врать «Подключено».
     */
    public function verify(WhatsappSession $session): JsonResponse
    {
        $this->authorize('manage', $session);

        if ($this->demoSessions->isDemoTenant()) {
            $session = $this->demoSessions->markConnected($session);

            return response()->json([
                'alive' => true,
                'reachable' => true,
                'is_ready' => true,
                'has_qr' => false,
                'is_initializing' => false,
                'session' => $session,
            ]);
        }

        if (! $this->whatsappService->healthReachable()) {
            return response()->json([
                'alive' => false,
                'reachable' => false,
                'message' => 'whatsapp-service недоступен.',
            ], 503);
        }

        $result = $this->whatsappService->verifySession($session->session_name);
        $alive = (bool) ($result['alive'] ?? false);
        $isReady = (bool) ($result['isReady'] ?? false);
        $hasQr = (bool) ($result['hasQR'] ?? false);
        $isInitializing = (bool) ($result['isInitializing'] ?? false);

        if ($alive) {
            $session->forceFill([
                'status' => 'connected',
                'connected_at' => $session->connected_at ?? now(),
                'qr_required_at' => null,
            ])->save();
        } elseif ($hasQr) {
            $session->forceFill([
                'status' => 'qr_pending',
                'qr_required_at' => $session->qr_required_at ?? now(),
            ])->save();
        } elseif (! $isReady && ! $isInitializing && $session->status === 'connected') {
            $session->forceFill([
                'status' => 'disconnected',
                'disconnected_at' => now(),
            ])->save();
        }

        return response()->json([
            'alive' => $alive,
            'reachable' => true,
            'state' => $result['state'] ?? null,
            'browser_connected' => (bool) ($result['browserConnected'] ?? false),
            'is_ready' => $isReady,
            'has_qr' => $hasQr,
            'is_initializing' => $isInitializing,
            'platform' => $result['platform'] ?? null,
            'latency_ms' => $result['latencyMs'] ?? null,
            'reasoning' => $result['reasoning'] ?? [],
            'session' => $session->fresh(),
        ]);
    }

    public function logout(WhatsappSession $session): JsonResponse
    {
        $this->whatsappService->logoutSession($session->session_name);

        // Явное «Выйти» ⇒ фиксируем намерение, чтобы watchdog не поднял назад.
        $session->update([
            'status' => 'disconnected',
            'desired_state' => WhatsappSession::DESIRED_LOGGED_OUT,
            'disconnected_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    public function destroy(WhatsappSession $session): JsonResponse
    {
        $this->authorize('manage', $session);

        $this->whatsappService->destroySession($session->session_name);

        $oldLabel = $session->display_name ?? $session->wa_name ?? $session->phone_number ?? $session->session_name;

        $replacement = $this->chatService->findReplacementWhatsappSession($session);
        $reattachedIds = $this->chatService->migrateGroupChatsToReplacementSession($session, $replacement);

        $replacementLabel = '';
        if ($replacement !== null) {
            $replacementLabel = $replacement->display_name
                ?? $replacement->wa_name
                ?? $replacement->phone_number
                ?? $replacement->session_name;
        }

        foreach ($reattachedIds as $chatId) {
            $chat = Chat::query()->find($chatId);
            if ($chat === null || $replacement === null) {
                continue;
            }
            $body = 'ℹ️ Группа переведена на «'.$replacementLabel.'». Удалённое подключение: «'.$oldLabel.'».';
            Message::create([
                'chat_id' => $chat->id,
                'whatsapp_session_id' => $replacement->id,
                'direction' => 'system',
                'type' => 'chat',
                'body' => $body,
                'message_timestamp' => now(),
            ]);
            $this->chatService->refreshChatLastMessageSnapshot($chat);
        }

        $disconnectNotice = '📵 Номер «'.$oldLabel.'» был отключён. Пожалуйста, подключите новый WhatsApp-номер в настройках.';

        $chats = Chat::query()->where('whatsapp_session_id', $session->id)->get();

        foreach ($chats as $chat) {
            Message::create([
                'chat_id' => $chat->id,
                'whatsapp_session_id' => null,
                'direction' => 'system',
                'type' => 'chat',
                'body' => $disconnectNotice,
                'message_timestamp' => now(),
            ]);
            $this->chatService->refreshChatLastMessageSnapshot($chat);
        }

        $session->delete();

        return response()->json(['success' => true]);
    }
}
