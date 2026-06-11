<?php

declare(strict_types=1);

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\TenantSignupRequest;
use App\Services\SuperAdmin\SuperAdminAuditLogger;
use App\Services\SuperAdmin\TenantSignupRejectionEmailService;
use App\Services\SuperAdmin\TenantWelcomeEmailService;
use App\Services\Tenancy\CompanyProvisioningService;
use App\Support\Bin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class SignupRequestController extends Controller
{
    public function __construct(
        private readonly SuperAdminAuditLogger $audit,
        private readonly TenantWelcomeEmailService $welcomeEmail,
        private readonly TenantSignupRejectionEmailService $rejectionEmail,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'in:pending,processed,rejected'],
        ]);

        $query = TenantSignupRequest::query()
            ->with(['company:id,name,slug', 'processedBy:id,name'])
            ->orderByDesc('id');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return Inertia::render('SuperAdmin/SignupRequests/Index', [
            'requests' => $query->paginate(30)->withQueryString(),
            'filters' => [
                'status' => $filters['status'] ?? '',
            ],
        ]);
    }

    public function approve(
        Request $request,
        TenantSignupRequest $signupRequest,
        CompanyProvisioningService $provisioning,
    ): RedirectResponse {
        $request->validate([
            'create_company' => ['accepted'],
        ], [
            'create_company.accepted' => 'Отметьте галочку «Создать компанию», чтобы подтвердить заявку.',
        ]);

        if ($signupRequest->status !== 'pending') {
            return back()->with('error', 'Заявка уже обработана.');
        }

        if ($signupRequest->company_id !== null) {
            return back()->with('error', 'Компания по этой заявке уже создана.');
        }

        if ($signupRequest->desired_slug === null || $signupRequest->desired_slug === '') {
            throw ValidationException::withMessages([
                'create_company' => 'В заявке не указан желаемый поддомен.',
            ]);
        }

        $slug = strtolower($signupRequest->desired_slug);
        $reserved = config('tenancy.reserved_slugs', []);

        if (in_array($slug, $reserved, true)) {
            throw ValidationException::withMessages([
                'create_company' => 'Поддомен зарезервирован. Создайте компанию вручную с другим slug.',
            ]);
        }

        if (Company::query()->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages([
                'create_company' => "Поддомен «{$slug}» уже занят. Создайте компанию вручную или отклоните заявку.",
            ]);
        }

        $result = $provisioning->create([
            'name' => $signupRequest->company_name,
            'slug' => $slug,
            'phone' => (string) $signupRequest->phone,
            'owner_name' => $signupRequest->contact_name,
            'owner_email' => $signupRequest->email,
        ]);

        $signupRequest->update([
            'status' => 'processed',
            'company_id' => $result['company']->id,
            'processed_by_user_id' => $request->user()?->id,
            'processed_at' => now(),
        ]);

        $company = $result['company'];

        $bin = Bin::normalize($signupRequest->bin);
        if ($bin !== null) {
            $company->update(['bin' => $bin]);
        }

        $actor = $request->user();

        $this->audit->log($company, $actor, 'company.created', $company, [
            'company_name' => $company->name,
            'slug' => $company->slug,
            'source' => 'signup_request',
            'signup_request_id' => $signupRequest->id,
            'owner_email' => $signupRequest->email,
        ]);

        $this->audit->log($company, $actor, 'signup_request.approved', $signupRequest, [
            'signup_request_id' => $signupRequest->id,
            'company_name' => $signupRequest->company_name,
            'slug' => $slug,
            'contact_email' => $signupRequest->email,
            'company_id' => $company->id,
        ]);

        $emailResult = $this->welcomeEmail->send(
            $company,
            $result['owner'],
            (string) $result['temporary_password'],
            $actor,
        );

        $message = 'Компания создана из заявки.';
        if ($emailResult['sent']) {
            $message .= ' Письмо с доступом отправлено на '.$emailResult['recipient'].'.';
        } else {
            $message .= ' Временный пароль владельца: '.$result['temporary_password'];
            if ($emailResult['error'] !== null) {
                return redirect()
                    ->route('super.companies.show', $company)
                    ->with('success', $message)
                    ->with('error', $emailResult['error']);
            }
        }

        return redirect()
            ->route('super.companies.show', $company)
            ->with('success', $message);
    }

    public function reject(Request $request, TenantSignupRequest $signupRequest): RedirectResponse
    {
        if ($signupRequest->status !== 'pending') {
            return back()->with('error', 'Заявка уже обработана.');
        }

        $signupRequest->update([
            'status' => 'rejected',
            'processed_by_user_id' => $request->user()?->id,
            'processed_at' => now(),
        ]);

        $this->audit->log(null, $request->user(), 'signup_request.rejected', $signupRequest, [
            'signup_request_id' => $signupRequest->id,
            'company_name' => $signupRequest->company_name,
            'slug' => $signupRequest->desired_slug,
            'contact_email' => $signupRequest->email,
        ]);

        $emailResult = $this->rejectionEmail->send($signupRequest, $request->user());

        $message = 'Заявка отклонена.';
        if ($emailResult['sent']) {
            $message .= ' Уведомление отправлено на '.$emailResult['recipient'].'.';
        } elseif ($emailResult['error'] !== null) {
            return back()
                ->with('success', $message)
                ->with('error', $emailResult['error']);
        }

        return back()->with('success', $message);
    }
}
