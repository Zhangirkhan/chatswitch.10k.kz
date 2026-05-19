<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AI\AiReadinessService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class OnboardingController extends Controller
{
    public function __construct(
        private readonly AiReadinessService $readinessService,
    ) {}

    public function index(): Response
    {
        $readiness = $this->readinessService->evaluate();
        $checksByKey = collect($readiness['checks'])->keyBy('key');

        $steps = [
            [
                'key' => 'whatsapp',
                'title' => 'Подключите WhatsApp',
                'description' => 'Добавьте рабочий номер и убедитесь, что сессия в статусе «подключено».',
                'route' => 'settings.connections',
                'ok' => (bool) ($checksByKey['whatsapp']['ok'] ?? false),
            ],
            [
                'key' => 'users',
                'title' => 'Добавьте сотрудников',
                'description' => 'Создайте операторов и руководителей, от имени которых AI сможет отвечать.',
                'route' => 'settings.users',
                'ok' => (bool) ($checksByKey['users']['ok'] ?? false),
            ],
            [
                'key' => 'departments',
                'title' => 'Настройте отделы',
                'description' => 'Отделы нужны для назначения ответственных и задач менеджерам.',
                'route' => 'settings.departments',
                'ok' => (bool) ($checksByKey['departments']['ok'] ?? false),
            ],
            [
                'key' => 'funnels',
                'title' => 'Создайте воронку продаж',
                'description' => 'Этапы и AI-правила задают, как AI ведёт клиента по сделке.',
                'route' => 'settings.funnels',
                'ok' => (bool) ($checksByKey['funnels']['ok'] ?? false),
            ],
            [
                'key' => 'scenarios',
                'title' => 'Включите AI-сценарий',
                'description' => 'Без сценария AI только отвечает, но не двигает сделку по этапам.',
                'route' => 'settings.funnels',
                'ok' => (bool) ($checksByKey['scenarios']['ok'] ?? false),
            ],
            [
                'key' => 'knowledge',
                'title' => 'Заполните базу знаний',
                'description' => 'Правила, товары и услуги защищают AI от выдуманных обещаний.',
                'route' => 'settings.knowledge.rules',
                'ok' => (bool) ($checksByKey['knowledge']['ok'] ?? false),
            ],
            [
                'key' => 'catalog',
                'title' => 'Добавьте каталог',
                'description' => 'Товары или услуги помогают AI отвечать предметно.',
                'route' => 'settings.knowledge.products',
                'ok' => (bool) ($checksByKey['catalog']['ok'] ?? false),
            ],
            [
                'key' => 'ai_quality',
                'title' => 'Проверьте готовность AI',
                'description' => 'Запустите симулятор и посмотрите очередь «требует внимания».',
                'route' => 'settings.ai-quality',
                'ok' => $readiness['status'] === 'ready',
            ],
        ];

        $completed = collect($steps)->filter(fn (array $step): bool => $step['ok'])->count();

        return Inertia::render('Settings/Onboarding', [
            'readiness' => $readiness,
            'steps' => $steps,
            'completed_steps' => $completed,
            'total_steps' => count($steps),
        ]);
    }

    public function complete(): RedirectResponse
    {
        $readiness = $this->readinessService->evaluate();
        if ($readiness['status'] !== 'ready') {
            return redirect()
                ->route('settings.onboarding')
                ->with('warning', 'Завершите все шаги онбординга — готовность AI ещё не достигнута.');
        }

        return redirect()
            ->route('settings.ai-quality')
            ->with('success', 'Онбординг завершён. AI готов к работе.');
    }
}
