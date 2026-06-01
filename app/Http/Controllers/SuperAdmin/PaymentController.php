<?php

declare(strict_types=1);

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Billing\SubscriptionLifecycleService;
use App\Services\SuperAdmin\SuperAdminAuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class PaymentController extends Controller
{
    public function __construct(
        private readonly SubscriptionLifecycleService $lifecycle,
        private readonly SuperAdminAuditLogger $audit,
    ) {}

    public function store(Request $request, Invoice $invoice): RedirectResponse
    {
        $data = $request->validate([
            'amount_cents' => ['required', 'integer', 'min:1'],
            'method' => ['required', 'string', Rule::in(['bank_transfer', 'kaspi', 'cash', 'other'])],
            'external_ref' => ['nullable', 'string', 'max:120'],
            'paid_at' => ['nullable', 'date'],
        ]);

        $activation = DB::transaction(function () use ($request, $invoice, $data): ?array {
            /** @var Invoice $locked */
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'paid') {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'invoice' => 'Счёт уже оплачен.',
                ]);
            }

            $paidAt = isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : now();

            $payment = Payment::query()->create([
                'invoice_id' => $locked->id,
                'company_id' => $locked->company_id,
                'amount_cents' => $data['amount_cents'],
                'method' => $data['method'],
                'external_ref' => $data['external_ref'] ?? null,
                'paid_at' => $paidAt,
                'recorded_by_user_id' => $request->user()?->id,
            ]);

            $locked->update([
                'status' => 'paid',
                'paid_at' => $paidAt,
            ]);

            $company = Company::query()->with('plan')->findOrFail($locked->company_id);

            $activation = $this->lifecycle->applyPaymentToSubscription(
                $company,
                (int) $data['amount_cents'],
            );

            if ($activation !== null) {
                $locked->update(['subscription_id' => $activation['subscription']->id]);
            }

            $this->audit->log($company, $request->user(), 'invoice.payment_recorded', $locked, [
                'payment_id' => $payment->id,
                'amount_cents' => $data['amount_cents'],
                'method' => $data['method'],
                'subscription_activated' => $activation !== null,
            ]);

            return $activation;
        });

        return back()->with('success', $this->successMessage($activation));
    }

    /**
     * @param array{subscription: \App\Models\Subscription, months: int, period_ends: CarbonInterface}|null $activation
     */
    private function successMessage(?array $activation): string
    {
        if ($activation === null) {
            return 'Платёж записан. Статус подписки не изменён (отменена или приостановлена).';
        }

        $ends = $activation['period_ends']->timezone(config('app.timezone'))->format('d.m.Y');
        $months = $activation['months'];

        return "Платёж записан. Подписка активирована на {$months} мес. (до {$ends}).";
    }
}
