<?php

declare(strict_types=1);

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Payment;
use App\Models\SuperAdminAuditLog;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'tab' => ['nullable', 'string', Rule::in(['actions', 'transactions'])],
            'action' => ['nullable', 'string', 'max:64'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'method' => ['nullable', 'string', Rule::in(['bank_transfer', 'kaspi', 'cash', 'other'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $tab = $filters['tab'] ?? 'actions';
        $companies = Company::query()->orderBy('name')->get(['id', 'name', 'slug']);

        $payload = [
            'tab' => $tab,
            'filters' => [
                'action' => $filters['action'] ?? '',
                'company_id' => isset($filters['company_id']) ? (string) $filters['company_id'] : '',
                'method' => $filters['method'] ?? '',
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
                'q' => $filters['q'] ?? '',
            ],
            'companies' => $companies,
        ];

        if ($tab === 'transactions') {
            $query = Payment::query()
                ->with([
                    'company:id,name,slug',
                    'invoice:id,number',
                    'recordedBy:id,name,email',
                ])
                ->orderByDesc('paid_at')
                ->orderByDesc('id');

            if (! empty($filters['company_id'])) {
                $query->where('company_id', (int) $filters['company_id']);
            }

            if (! empty($filters['method'])) {
                $query->where('method', $filters['method']);
            }

            if (! empty($filters['from'])) {
                $query->whereDate('paid_at', '>=', $filters['from']);
            }

            if (! empty($filters['to'])) {
                $query->whereDate('paid_at', '<=', $filters['to']);
            }

            if (! empty($filters['q'])) {
                $term = '%'.addcslashes($filters['q'], '%_\\').'%';
                $query->where(function ($q) use ($term): void {
                    $q->where('external_ref', 'like', $term)
                        ->orWhereHas('company', fn ($cq) => $cq->where('name', 'like', $term)->orWhere('slug', 'like', $term))
                        ->orWhereHas('invoice', fn ($iq) => $iq->where('number', 'like', $term))
                        ->orWhereHas('recordedBy', fn ($uq) => $uq->where('name', 'like', $term)->orWhere('email', 'like', $term));
                });
            }

            return Inertia::render('SuperAdmin/AuditLogs/Index', [
                ...$payload,
                'transactions' => $query->paginate(50)->withQueryString(),
                'auditLogs' => null,
                'actions' => [],
            ]);
        }

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
            ...$payload,
            'auditLogs' => $query->paginate(50)->withQueryString(),
            'transactions' => null,
            'actions' => SuperAdminAuditLog::query()
                ->select('action')
                ->distinct()
                ->orderBy('action')
                ->pluck('action'),
        ]);
    }
}
