<?php

declare(strict_types=1);

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Services\SuperAdmin\CompanyExportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CompanyExportController extends Controller
{
    public function __invoke(Request $request, CompanyExportService $export): StreamedResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'is_active' => ['nullable', 'string', 'in:1,0'],
            'subscription_status' => ['nullable', 'string', 'in:trial,active,past_due,suspended,canceled'],
            'plan_id' => ['nullable', 'integer', 'exists:plans,id'],
            'sort' => ['nullable', 'string', 'in:created_desc,created_asc,name'],
        ]);

        $filename = 'accel-companies-'.now()->format('Y-m-d-Hi').'.xlsx';

        return response()->stream(
            function () use ($export, $request, $filters): void {
                $export->exportToStream($request->user(), $filters, 'php://output');
            },
            200,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                'Cache-Control' => 'max-age=0',
            ],
        );
    }
}
