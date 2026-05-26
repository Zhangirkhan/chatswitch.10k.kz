<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\TenantSignupRequest;
use App\Services\Tenancy\TenantSlugAvailabilityService;
use App\Support\PhoneFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class LandingController extends Controller
{
    public function home(): Response
    {
        return Inertia::render('Landing/Home', [
            'rootDomain' => (string) config('tenancy.root_domain', 'accel.kz'),
        ]);
    }

    public function notFound(Request $request): SymfonyResponse
    {
        $reason = $request->query('reason');
        $from = $request->query('from');

        return Inertia::render('Landing/NotFound', [
            'attemptedHost' => is_string($from) && $from !== '' ? $from : null,
            'reason' => $reason === 'unknown_tenant' ? 'unknown_tenant' : 'not_found',
        ])
            ->toResponse($request)
            ->setStatusCode(SymfonyResponse::HTTP_NOT_FOUND);
    }

    public function checkTenantSlug(Request $request, TenantSlugAvailabilityService $availability): JsonResponse
    {
        $data = $request->validate([
            'slug' => ['required', 'string', 'max:32'],
        ]);

        return response()->json($availability->check($data['slug']));
    }

    public function signupRequest(Request $request): RedirectResponse
    {
        $reserved = config('tenancy.reserved_slugs', []);

        $request->merge([
            'bin' => preg_replace('/\D+/', '', (string) $request->input('bin', '')),
        ]);

        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:160'],
            'bin' => ['required', 'string', 'regex:/^\d{12}$/'],
            'desired_slug' => [
                'required',
                'string',
                'max:32',
                'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/',
                Rule::notIn($reserved),
                Rule::unique(Company::class, 'slug'),
            ],
            'contact_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160'],
            'phone' => ['required', 'string', 'max:40'],
            'message' => ['nullable', 'string', 'max:2000'],
            'terms_accepted' => ['accepted'],
        ], [
            'bin.regex' => 'Укажите БИН из 12 цифр.',
            'terms_accepted.accepted' => 'Необходимо согласие с условиями подключения и оплаты.',
            'desired_slug.regex' => 'Поддомен: только латиница, цифры и дефис (например my-company).',
            'desired_slug.not_in' => 'Этот поддомен зарезервирован.',
            'desired_slug.unique' => 'Такой поддомен уже занят — выберите другой.',
        ]);

        $phone = PhoneFormatter::normalize($data['phone']);
        if ($phone === null || strlen($phone) < 11) {
            return back()->withErrors(['phone' => 'Укажите полный номер телефона.'])->withInput();
        }

        unset($data['terms_accepted']);

        TenantSignupRequest::query()->create([
            ...$data,
            'phone' => $phone,
            'terms_accepted_at' => now(),
        ]);

        return back()->with(
            'success',
            'Заявка отправлена. После проверки мы создадим ваше рабочее пространство и пришлём данные для входа на email.',
        );
    }
}
