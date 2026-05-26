<?php

declare(strict_types=1);

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Services\SuperAdmin\CompanyDemoMaintenanceService;
use App\Services\SuperAdmin\DemoTenantPopulationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class CompanyMaintenanceController extends Controller
{
    public function __construct(
        private readonly CompanyDemoMaintenanceService $maintenance,
        private readonly DemoTenantPopulationService $demoPopulation,
    ) {}

    public function populateDemoTenant(Request $request): RedirectResponse
    {
        $result = $this->demoPopulation->populate($request->user());
        $stats = $result['stats'];

        return back()->with('success', sprintf(
            'Демо-тенант заполнен: %d чатов, %d сообщений, %d этапов воронки. Вход: %s / %s',
            $stats['chats'],
            $stats['messages'],
            $stats['funnel_stages'],
            $stats['login'],
            $stats['password'],
        ));
    }

    public function seedTestData(Request $request): RedirectResponse
    {
        $created = $this->maintenance->seedTestCompanies($request->user());

        if ($created === 0) {
            return back()->with('success', 'Тестовые компании уже созданы — новых записей не добавлено.');
        }

        return back()->with('success', "Добавлено тестовых компаний: {$created}.");
    }

    public function destroyAllExceptDemo(Request $request): RedirectResponse
    {
        $deleted = $this->maintenance->deleteAllExceptDemo($request->user());

        if ($deleted === 0) {
            return back()->with('success', 'Нет компаний для удаления (демо-тенант сохранён).');
        }

        return back()->with('success', "Удалено компаний: {$deleted}. Демо-тенант сохранён.");
    }
}
