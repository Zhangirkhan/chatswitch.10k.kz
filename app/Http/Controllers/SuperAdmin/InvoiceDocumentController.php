<?php

declare(strict_types=1);

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\SuperAdmin\InvoiceEmailService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class InvoiceDocumentController extends Controller
{
    public function __construct(
        private readonly InvoiceEmailService $invoiceEmail,
    ) {}

    public function print(Invoice $invoice): View
    {
        $invoice->load('company.plan');
        $company = $invoice->company;
        abort_if($company === null, 404);

        $statusLabels = [
            'draft' => 'Черновик',
            'issued' => 'Выставлен',
            'paid' => 'Оплачен',
            'void' => 'Аннулирован',
        ];

        return view('invoices.print', [
            'invoice' => $invoice,
            'company' => $company,
            'amountLabel' => $this->amountLabel($invoice),
            'statusLabel' => $statusLabels[$invoice->status] ?? $invoice->status,
            'seller' => config('billing.seller'),
        ]);
    }

    public function email(Request $request, Invoice $invoice): RedirectResponse
    {
        $override = $request->validate([
            'email' => ['nullable', 'email', 'max:160'],
        ])['email'] ?? null;

        $result = $this->invoiceEmail->send($invoice, $request->user(), $override);

        if (! $result['sent']) {
            return back()->withErrors(['email' => $result['error'] ?? 'Не удалось отправить.']);
        }

        return back()->with('success', 'Счёт отправлен на '.$result['recipient']);
    }
}
