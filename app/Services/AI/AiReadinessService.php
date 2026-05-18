<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Department;
use App\Models\Funnel;
use App\Models\FunnelAiScenario;
use App\Models\FunnelStageAiRule;
use App\Models\KnowledgeRule;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Support\TenantCompany;

final class AiReadinessService
{
    public const READY_SCORE = 65;

    /**
     * @return array{score: int, status: string, label: string, summary: string, checks: list<array{key: string, label: string, ok: bool, value: string, hint: string}>, next_actions: list<string>}
     */
    public function evaluate(?int $companyId = null): array
    {
        $companyId ??= TenantCompany::id();
        $counts = [
            'whatsapp_sessions' => WhatsappSession::query()->whereIn('status', ['connected', 'ready', 'authenticated'])->count(),
            'active_users' => User::query()->where('company_id', $companyId)->where('is_active', true)->count(),
            'departments' => Department::query()->where('is_active', true)->count(),
            'funnels' => Funnel::query()->where('company_id', $companyId)->where('is_active', true)->count(),
            'enabled_scenarios' => FunnelAiScenario::query()->where('company_id', $companyId)->where('enabled', true)->count(),
            'stage_rules' => FunnelStageAiRule::query()->where('company_id', $companyId)->count(),
            'knowledge_rules' => KnowledgeRule::query()->where('company_id', $companyId)->where('is_active', true)->where('include_in_prompt', true)->count(),
            'products' => Product::query()->where('company_id', $companyId)->where('is_active', true)->where('include_in_prompt', true)->count(),
            'services' => Service::query()->where('company_id', $companyId)->where('is_active', true)->where('include_in_prompt', true)->count(),
        ];

        $checks = [
            [
                'key' => 'whatsapp',
                'label' => 'WhatsApp подключён',
                'ok' => $counts['whatsapp_sessions'] > 0,
                'value' => (string) $counts['whatsapp_sessions'],
                'hint' => 'Без активного подключения AI не сможет отвечать клиентам в реальном канале.',
            ],
            [
                'key' => 'users',
                'label' => 'Есть активные сотрудники',
                'ok' => $counts['active_users'] > 0,
                'value' => (string) $counts['active_users'],
                'hint' => 'Нужен хотя бы один активный пользователь, от имени которого AI может работать.',
            ],
            [
                'key' => 'departments',
                'label' => 'Настроены отделы',
                'ok' => $counts['departments'] > 0,
                'value' => (string) $counts['departments'],
                'hint' => 'Отделы нужны для назначения ответственных, задач и передачи менеджеру.',
            ],
            [
                'key' => 'funnels',
                'label' => 'Есть активная воронка',
                'ok' => $counts['funnels'] > 0,
                'value' => (string) $counts['funnels'],
                'hint' => 'AI должен понимать, по каким этапам вести клиента.',
            ],
            [
                'key' => 'scenarios',
                'label' => 'AI-сценарии включены',
                'ok' => $counts['enabled_scenarios'] > 0,
                'value' => (string) $counts['enabled_scenarios'],
                'hint' => 'Без включённого сценария AI будет только отвечать, но не вести сделку.',
            ],
            [
                'key' => 'stage_rules',
                'label' => 'У этапов есть AI-правила',
                'ok' => $counts['stage_rules'] >= 5,
                'value' => (string) $counts['stage_rules'],
                'hint' => 'Правила этапов задают цель, вопросы, условия перехода и разрешённые действия.',
            ],
            [
                'key' => 'knowledge',
                'label' => 'База знаний включена в промпт',
                'ok' => $counts['knowledge_rules'] >= 3,
                'value' => (string) $counts['knowledge_rules'],
                'hint' => 'Guardrails и факты защищают от повторов, выдуманных сроков и неверных обещаний.',
            ],
            [
                'key' => 'catalog',
                'label' => 'Есть товары или услуги',
                'ok' => ($counts['products'] + $counts['services']) > 0,
                'value' => $counts['products'].' / '.$counts['services'],
                'hint' => 'Каталог помогает AI отвечать предметно и задавать меньше лишних вопросов.',
            ],
        ];

        $okCount = collect($checks)->filter(fn (array $check): bool => $check['ok'])->count();
        $score = (int) round($okCount / max(1, count($checks)) * 100);
        $status = match (true) {
            $score >= 90 => 'ready',
            $score >= self::READY_SCORE => 'partial',
            default => 'risk',
        };

        $nextActions = collect($checks)
            ->reject(fn (array $check): bool => $check['ok'])
            ->map(fn (array $check): string => $check['hint'])
            ->take(4)
            ->values()
            ->all();

        return [
            'score' => $score,
            'status' => $status,
            'label' => match ($status) {
                'ready' => 'AI готов к работе',
                'partial' => 'AI почти готов',
                default => 'AI пока рискованно включать',
            },
            'summary' => match ($status) {
                'ready' => 'Основные блоки настроены. AI может вести клиентов с минимальным риском.',
                'partial' => 'Часть настроек готова, но есть пробелы. Проверьте рекомендации перед массовым включением AI.',
                default => 'Слишком много пробелов в настройке. Сначала закройте критичные пункты.',
            },
            'checks' => $checks,
            'next_actions' => $nextActions,
        ];
    }

    public function isReadyForEnable(?int $companyId = null): bool
    {
        return $this->evaluate($companyId)['score'] >= self::READY_SCORE;
    }

    /**
     * @return list<string>
     */
    public function enableBlockers(?int $companyId = null): array
    {
        $readiness = $this->evaluate($companyId);
        $blockers = [];

        if ($readiness['score'] < self::READY_SCORE) {
            $blockers[] = 'Готовность AI: '.$readiness['score'].'% (нужно минимум '.self::READY_SCORE.'%).';
        }

        $knowledge = collect($readiness['checks'])->firstWhere('key', 'knowledge');
        if (is_array($knowledge) && ! $knowledge['ok']) {
            $blockers[] = 'В промпт не включено достаточно правил базы знаний.';
        }

        $users = collect($readiness['checks'])->firstWhere('key', 'users');
        if (is_array($users) && ! $users['ok']) {
            $blockers[] = 'Нет активных сотрудников для ответа от имени AI.';
        }

        return $blockers;
    }
}
