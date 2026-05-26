<?php

declare(strict_types=1);

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class GlobalInvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'in:issued,paid,void'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $query = Invoice::query()
            ->with(['company:id,name,slug'])
            ->orderByDesc('id');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['company_id'])) {
            $query->where('company_id', (int) $filters['company_id']);
        }

        if (! empty($filters['from'])) {
            $query->whereDate('issued_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('issued_at', '<=', $filters['to']);
        }

        if (! empty($filters['q'])) {
            $term = '%'.addcslashes($filters['q'], '%_\\').'%';
            $query->where(function ($q) use ($term): void {
                $q->where('number', 'like', $term)
                    ->orWhereHas('company', fn ($cq) => $cq->where('name', 'like', $term)->orWhere('slug', 'like', $term));
            });
        }

        return Inertia::render('SuperAdmin/Invoices/Index', [
            'invoices' => $query->paginate(30)->withQueryString(),
            'filters' => [
                'status' => $filters['status'] ?? '',
                'company_id' => isset($filters['company_id']) ? (string) $filters['company_id'] : '',
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
                'q' => $filters['q'] ?? '',
            ],
            'companies' => Company::query()->orderBy('name')->get(['id', 'name', 'slug']),
        ]);
    }
}
