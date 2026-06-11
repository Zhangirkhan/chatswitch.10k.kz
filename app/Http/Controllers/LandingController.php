<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Plan;
use App\Models\TenantSignupRequest;
use App\Services\Marketing\AiTokenCalculatorService;
use App\Services\Mobile\MobileAppReleaseService;
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
    public function home(MobileAppReleaseService $mobileReleases): Response
    {
        $androidRelease = $mobileReleases->latestPublished('android');

        return Inertia::render('Landing/Home', [
            'rootDomain' => (string) config('tenancy.root_domain', 'accel.kz'),
            'androidApkUrl' => $androidRelease !== null
                ? $mobileReleases->absoluteDownloadUrl($androidRelease->download_url)
                : url('/apk/app-release.apk'),
            'pricingPlans' => Plan::query()
                ->where('is_active', true)
                ->whereIn('code', ['standard', 'boxed'])
                ->orderBy('price_cents')
                ->get(['code', 'price_cents', 'currency', 'interval'])
                ->map(static fn (Plan $plan): array => [
                    'code' => $plan->code,
                    'price' => $plan->formattedPrice(),
                    'interval' => $plan->interval,
                ])
                ->values()
                ->all(),
        ]);
    }

    public function calculator(AiTokenCalculatorService $calculator): Response
    {
        return Inertia::render('Landing/Calculator', [
            'rootDomain' => (string) config('tenancy.root_domain', 'accel.kz'),
            'calculator' => $calculator->payloadForFrontend(),
        ]);
    }

    public function faq(MobileAppReleaseService $mobileReleases): Response
    {
        $androidRelease = $mobileReleases->latestPublished('android');

        return Inertia::render('Landing/Faq', [
            'rootDomain' => (string) config('tenancy.root_domain', 'accel.kz'),
            'androidApkUrl' => $androidRelease !== null
                ? $mobileReleases->absoluteDownloadUrl($androidRelease->download_url)
                : url('/apk/app-release.apk'),
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
            'bin.regex' => __('landing.bin_regex'),
            'terms_accepted.accepted' => __('landing.terms_accepted'),
            'desired_slug.regex' => __('landing.desired_slug_regex'),
            'desired_slug.not_in' => __('landing.desired_slug_not_in'),
            'desired_slug.unique' => __('landing.desired_slug_unique'),
        ]);

        $phone = PhoneFormatter::normalize($data['phone']);
        if ($phone === null || strlen($phone) < 11) {
            return back()->withErrors(['phone' => __('landing.phone_invalid')])->withInput();
        }

        unset($data['terms_accepted']);

        TenantSignupRequest::query()->create([
            ...$data,
            'phone' => $phone,
            'terms_accepted_at' => now(),
        ]);

        return back()->with(
            'success',
            __('landing.signup_success'),
        );
    }
}
