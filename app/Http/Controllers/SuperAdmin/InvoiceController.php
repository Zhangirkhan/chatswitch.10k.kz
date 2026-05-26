<?php

declare(strict_types=1);

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\Billing\SubscriptionLifecycleService;
use App\Services\SuperAdmin\InvoiceEmailService;
use App\Services\SuperAdmin\SuperAdminAuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

final class InvoiceController extends Controller
{
    public function __construct(
        private readonly SuperAdminAuditLogger $audit,
        private readonly SubscriptionLifecycleService $lifecycle,
        private readonly InvoiceEmailService $invoiceEmail,
    ) {}

    public function index(Company $company): RedirectResponse
    {
        return redirect()->to(route('super.companies.show', $company).'?tab=invoices');
    }

    public function store(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate([
            'number' => ['required', 'string', 'max:64', Rule::unique('invoices', 'number')->where('company_id', $company->id)],
            'amount_cents' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'send_email' => ['boolean'],
        ]);

        $invoice = $company->invoices()->create([
            'number' => $data['number'],
            'amount_cents' => $data['amount_cents'],
            'currency' => $data['currency'],
            'notes' => $data['notes'] ?? null,
            'status' => 'issued',
            'issued_at' => now(),
        ]);

        $this->audit->log($company, $request->user(), 'invoice.issued', $invoice, [
            'number' => $invoice->number,
            'amount_cents' => $invoice->amount_cents,
        ]);

        $message = 'Счёт выставлен.';

        if ($request->boolean('send_email')) {
            $emailResult = $this->invoiceEmail->send($invoice, $request->user());
            if ($emailResult['sent']) {
                $message .= ' Отправлен на '.$emailResult['recipient'].'.';
            } else {
                return back()
                    ->with('success', $message)
                    ->with('error', $emailResult['error'] ?? 'Не удалось отправить счёт на email.');
            }
        }

        return back()->with('success', $message);
    }

    public function update(Request $request, Invoice $invoice): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(['draft', 'issued', 'paid', 'void'])],
            'paid_at' => ['nullable', 'date'],
        ]);

        $previousStatus = $invoice->status;
        $invoice->update($data);

        if ($data['status'] === 'paid' && $invoice->paid_at === null) {
            $invoice->update(['paid_at' => isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : now()]);
        }

        $company = Company::query()->with('plan')->findOrFail($invoice->company_id);

        if ($data['status'] === 'paid' && $previousStatus !== 'paid') {
            $activation = $this->lifecycle->applyPaymentToSubscription($company, $invoice->amount_cents);
            if ($activation !== null) {
                $invoice->update(['subscription_id' => $activation['subscription']->id]);
            }
        }

        $this->audit->log($company, $request->user(), 'invoice.status_changed', $invoice, [
            'from' => $previousStatus,
            'to' => $data['status'],
        ]);

        return back()->with('success', 'Счёт обновлён.');
    }
}
