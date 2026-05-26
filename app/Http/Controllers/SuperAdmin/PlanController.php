<?php

declare(strict_types=1);

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class PlanController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('SuperAdmin/Plans/Index', [
            'plans' => Plan::query()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Plan::query()->create($this->validated($request));

        return back()->with('success', 'Тариф создан.');
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        $plan->update($this->validated($request, $plan));

        return back()->with('success', 'Тариф обновлён.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Plan $plan = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64', Rule::unique('plans', 'code')->ignore($plan?->id)],
            'name' => ['required', 'string', 'max:120'],
            'price_cents' => ['required', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'interval' => ['required', 'string', Rule::in(['month', 'year'])],
            'trial_days' => ['required', 'integer', 'min:0', 'max:90'],
            'is_active' => ['boolean'],
        ]);

        $data['is_active'] = (bool) ($data['is_active'] ?? true);
        $data['trial_days'] = (int) $data['trial_days'];

        return $data;
    }
}
