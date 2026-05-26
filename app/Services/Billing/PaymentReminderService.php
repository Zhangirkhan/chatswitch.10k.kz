<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Mail\PaymentReminderMail;
use App\Models\BillingReminderLog;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\SuperAdmin\SuperAdminAuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

final class PaymentReminderService
{
    public function __construct(
        private readonly BillingRecipientResolver $recipients,
        private readonly SuperAdminAuditLogger $audit,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('billing.payment_reminders.enabled', true);
    }

    /**
     * @return list<int>
     */
    public function daysBeforeOptions(): array
    {
        $raw = config('billing.payment_reminders.days_before', [7, 3, 1]);
        if (! is_array($raw)) {
            return [7, 3, 1];
        }

        $days = array_values(array_unique(array_map(
            static fn ($v) => max(1, min(60, (int) $v)),
            $raw,
        )));
        rsort($days);

        return $days !== [] ? $days : [7, 3, 1];
    }

    /**
     * @return array{sent: int, skipped: int, failed: int}
     */
    public function sendDueReminders(?CarbonInterface $onDate = null): array
    {
        if (! $this->enabled()) {
            return ['sent' => 0, 'skipped' => 0, 'failed' => 0];
        }

        $today = Carbon::instance(($onDate ?? now())->toDateTime())->startOfDay();
        $sent = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($this->daysBeforeOptions() as $daysBefore) {
            $this->processTrialReminders($today, $daysBefore, $sent, $skipped, $failed);
            $this->processRenewalReminders($today, $daysBefore, $sent, $skipped, $failed);
        }

        if ($sent > 0) {
            Log::info('Billing payment reminders sent', compact('sent', 'skipped', 'failed'));
        }

        return compact('sent', 'skipped', 'failed');
    }

    private function processTrialReminders(
        CarbonInterface $today,
        int $daysBefore,
        int &$sent,
        int &$skipped,
        int &$failed,
    ): void {
        Company::query()
            ->withoutGlobalScope('tenant')
            ->where('is_active', true)
            ->where('subscription_status', 'trial')
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '>', now())
            ->with(['plan', 'owner'])
            ->orderBy('id')
            ->each(function (Company $company) use ($today, $daysBefore, &$sent, &$skipped, &$failed): void {
                $dueAt = Carbon::parse($company->trial_ends_at);
                if (! $this->shouldRemindOn($today, $dueAt, $daysBefore)) {
                    return;
                }

                $this->sendReminder(
                    $company,
                    BillingReminderLog::KIND_TRIAL_ENDING,
                    $dueAt,
                    $daysBefore,
                    $sent,
                    $skipped,
                    $failed,
                );
            });
    }

    private function processRenewalReminders(
        CarbonInterface $today,
        int $daysBefore,
        int &$sent,
        int &$skipped,
        int &$failed,
    ): void {
        Company::query()
            ->withoutGlobalScope('tenant')
            ->where('is_active', true)
            ->where('subscription_status', 'active')
            ->whereNotNull('current_period_ends_at')
            ->where('current_period_ends_at', '>', now())
            ->with(['plan', 'owner'])
            ->orderBy('id')
            ->each(function (Company $company) use ($today, $daysBefore, &$sent, &$skipped, &$failed): void {
                $dueAt = Carbon::parse($company->current_period_ends_at);
                if (! $this->shouldRemindOn($today, $dueAt, $daysBefore)) {
                    return;
                }

                $this->sendReminder(
                    $company,
                    BillingReminderLog::KIND_PERIOD_RENEWAL,
                    $dueAt,
                    $daysBefore,
                    $sent,
                    $skipped,
                    $failed,
                );
            });
    }

    private function shouldRemindOn(CarbonInterface $today, CarbonInterface $dueAt, int $daysBefore): bool
    {
        $reminderDay = $dueAt->copy()->subDays($daysBefore)->startOfDay();

        return $reminderDay->equalTo($today);
    }

    private function sendReminder(
        Company $company,
        string $kind,
        CarbonInterface $dueAt,
        int $daysBefore,
        int &$sent,
        int &$skipped,
        int &$failed,
    ): void {
        $dueOn = $dueAt->copy()->startOfDay()->toDateString();

        if ($this->alreadySent($company->id, $kind, $dueOn, $daysBefore)) {
            $skipped++;

            return;
        }

        $recipient = $this->recipients->resolve($company);
        if ($recipient === null) {
            Log::warning('Billing payment reminder skipped: no recipient email', [
                'company_id' => $company->id,
                'kind' => $kind,
            ]);
            $skipped++;

            return;
        }

        $plan = $company->plan;
        $amountLabel = $plan !== null
            ? $plan->pricePerMonthLabel()
            : $this->defaultAmountLabel();

        $invoicePrintUrl = $this->latestOpenInvoicePrintUrl($company);

        try {
            Mail::to($recipient)->send(new PaymentReminderMail(
                $company,
                $kind,
                $dueAt,
                $daysBefore,
                $amountLabel,
                $company->tenantUrl('/login'),
                $invoicePrintUrl,
            ));
        } catch (\Throwable $e) {
            report($e);
            $failed++;

            return;
        }

        BillingReminderLog::query()->create([
            'company_id' => $company->id,
            'kind' => $kind,
            'due_on' => $dueOn,
            'days_before' => $daysBefore,
            'recipient' => $recipient,
            'sent_at' => now(),
        ]);

        $this->audit->log($company, null, 'billing.payment_reminder_sent', $company, [
            'kind' => $kind,
            'due_on' => $dueOn,
            'days_before' => $daysBefore,
            'recipient' => $recipient,
        ]);

        $sent++;
    }

    private function alreadySent(int $companyId, string $kind, string $dueOn, int $daysBefore): bool
    {
        return BillingReminderLog::query()
            ->where('company_id', $companyId)
            ->where('kind', $kind)
            ->whereDate('due_on', $dueOn)
            ->where('days_before', $daysBefore)
            ->exists();
    }

    private function latestOpenInvoicePrintUrl(Company $company): ?string
    {
        $invoice = Invoice::query()
            ->where('company_id', $company->id)
            ->where('status', 'issued')
            ->orderByDesc('issued_at')
            ->first();

        if ($invoice === null) {
            return null;
        }

        return route('super.invoices.print', $invoice, absolute: true);
    }

    private function defaultAmountLabel(): string
    {
        $cents = (int) config('billing.standard_price_cents', 4_000_000);
        $tenge = number_format($cents / 100, 0, ',', ' ');

        return $tenge.' ₸ / мес.';
    }
}
