<?php

declare(strict_types=1);

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\SuperAdminAuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:64'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $query = SuperAdminAuditLog::query()
            ->with(['company:id,name,slug', 'actor:id,name,email'])
            ->orderByDesc('id');

        if (! empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        if (! empty($filters['company_id'])) {
            $query->where('company_id', (int) $filters['company_id']);
        }

        if (! empty($filters['q'])) {
            $term = '%'.addcslashes($filters['q'], '%_\\').'%';
            $query->where(function ($q) use ($term): void {
                $q->where('action', 'like', $term)
                    ->orWhereHas('company', fn ($cq) => $cq->where('name', 'like', $term)->orWhere('slug', 'like', $term))
                    ->orWhereHas('actor', fn ($aq) => $aq->where('name', 'like', $term)->orWhere('email', 'like', $term));
            });
        }

        return Inertia::render('SuperAdmin/AuditLogs/Index', [
            'auditLogs' => $query->paginate(50)->withQueryString(),
            'filters' => [
                'action' => $filters['action'] ?? '',
                'company_id' => isset($filters['company_id']) ? (string) $filters['company_id'] : '',
                'q' => $filters['q'] ?? '',
            ],
            'companies' => Company::query()->orderBy('name')->get(['id', 'name', 'slug']),
            'actions' => SuperAdminAuditLog::query()
                ->select('action')
                ->distinct()
                ->orderBy('action')
                ->pluck('action'),
        ]);
    }
}
