<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\SuperAdmin\AuditLogController;
use App\Http\Controllers\SuperAdmin\CompanyDoctorController;
use App\Http\Controllers\SuperAdmin\ContactMessageController;
use App\Http\Controllers\SuperAdmin\CompanyController;
use App\Http\Controllers\SuperAdmin\CompanyMaintenanceController;
use App\Http\Controllers\SuperAdmin\CompanyModuleController;
use App\Http\Controllers\SuperAdmin\CompanyImpersonationController;
use App\Http\Controllers\SuperAdmin\CompanyUserController;
use App\Http\Controllers\SuperAdmin\CompanyWhatsappController;
use App\Http\Controllers\SuperAdmin\GlobalInvoiceController;
use App\Http\Controllers\SuperAdmin\InvoiceDocumentController;
use App\Http\Controllers\SuperAdmin\DashboardController;
use App\Http\Controllers\SuperAdmin\InvoiceController;
use App\Http\Controllers\SuperAdmin\MobileAppReleaseController;
use App\Http\Controllers\SuperAdmin\PaymentController;
use App\Http\Controllers\SuperAdmin\PlanController;
use App\Http\Controllers\SuperAdmin\SignupRequestController;
use App\Http\Controllers\SuperAdmin\SubscriptionController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('super.dashboard'));

// Аутентификация супер-админки — отдельные имена, чтобы не конфликтовать
// с тенантным /login, который тоже использует имя "login".
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('super.login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('super.login.attempt');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('super.logout');
});

Route::middleware(['auth', 'super.admin'])->group(function (): void {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('super.dashboard');

    Route::get('/invoices', [GlobalInvoiceController::class, 'index'])->name('super.invoices.global');

    Route::get('/companies', [CompanyController::class, 'index'])->name('super.companies.index');
    Route::get('/companies/create', [CompanyController::class, 'create'])->name('super.companies.create');
    Route::post('/companies', [CompanyController::class, 'store'])->name('super.companies.store');
    Route::post('/companies/{company}/populate-sandbox', [CompanyController::class, 'populateSandbox'])
        ->name('super.companies.populate-sandbox');
    Route::delete('/companies/{company}/sandbox-data', [CompanyController::class, 'clearSandboxData'])
        ->name('super.companies.clear-sandbox-data');
    Route::put('/companies/{company}/modules', [CompanyModuleController::class, 'update'])
        ->name('super.companies.modules.update');
    Route::get('/companies/{company}', [CompanyController::class, 'show'])->name('super.companies.show');
    Route::put('/companies/{company}', [CompanyController::class, 'update'])->name('super.companies.update');
    Route::delete('/companies/{company}', [CompanyController::class, 'destroy'])
        ->whereNumber('company')
        ->name('super.companies.destroy');
    Route::post('/companies/{company}/doctor-fix', [CompanyDoctorController::class, 'fix'])
        ->name('super.companies.doctor-fix');
    Route::patch('/companies/{company}/toggle-active', [CompanyController::class, 'toggleActive'])->name('super.companies.toggle-active');
    Route::post('/companies/{company}/impersonate', [CompanyImpersonationController::class, 'store'])
        ->name('super.companies.impersonate');

    Route::post('/companies/{company}/users', [CompanyUserController::class, 'store'])->name('super.companies.users.store');
    Route::put('/companies/{company}/users/{user}', [CompanyUserController::class, 'update'])->name('super.companies.users.update');
    Route::post('/companies/{company}/users/{user}/reset-password', [CompanyUserController::class, 'resetPassword'])->name('super.companies.users.reset-password');

    Route::get('/companies/{company}/whatsapp-sessions/{session}/qr', [CompanyWhatsappController::class, 'qr'])->name('super.companies.whatsapp.qr');
    Route::get('/companies/{company}/whatsapp-sessions/{session}/status', [CompanyWhatsappController::class, 'status'])->name('super.companies.whatsapp.status');

    Route::post('/companies/{company}/subscriptions', [SubscriptionController::class, 'store'])->name('super.subscriptions.store');
    Route::post('/companies/{company}/subscriptions/activate', [SubscriptionController::class, 'activate'])->name('super.subscriptions.activate');
    Route::post('/companies/{company}/subscriptions/cancel', [SubscriptionController::class, 'cancel'])->name('super.subscriptions.cancel');
    Route::put('/subscriptions/{subscription}', [SubscriptionController::class, 'update'])->name('super.subscriptions.update');

    Route::get('/companies/{company}/invoices', [InvoiceController::class, 'index'])->name('super.invoices.index');
    Route::post('/companies/{company}/invoices', [InvoiceController::class, 'store'])->name('super.invoices.store');
    Route::put('/invoices/{invoice}', [InvoiceController::class, 'update'])->name('super.invoices.update');
    Route::get('/invoices/{invoice}/print', [InvoiceDocumentController::class, 'print'])->name('super.invoices.print');
    Route::post('/invoices/{invoice}/email', [InvoiceDocumentController::class, 'email'])->name('super.invoices.email');

    Route::post('/invoices/{invoice}/payments', [PaymentController::class, 'store'])->name('super.payments.store');
});

Route::middleware(['auth', 'super.admin', 'super.admin.global'])->group(function (): void {
    Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('super.audit-logs.index');

    Route::post('/companies/populate-demo', [CompanyMaintenanceController::class, 'populateDemoTenant'])
        ->name('super.companies.populate-demo');
    Route::post('/companies/seed-test-data', [CompanyMaintenanceController::class, 'seedTestData'])
        ->name('super.companies.seed-test-data');
    Route::delete('/companies/non-demo', [CompanyMaintenanceController::class, 'destroyAllExceptDemo'])
        ->name('super.companies.destroy-non-demo');

    Route::get('/plans', [PlanController::class, 'index'])->name('super.plans.index');
    Route::post('/plans', [PlanController::class, 'store'])->name('super.plans.store');
    Route::put('/plans/{plan}', [PlanController::class, 'update'])->name('super.plans.update');

    Route::get('/signup-requests', [SignupRequestController::class, 'index'])->name('super.signup-requests.index');
    Route::post('/signup-requests/{signupRequest}/approve', [SignupRequestController::class, 'approve'])
        ->name('super.signup-requests.approve');
    Route::post('/signup-requests/{signupRequest}/reject', [SignupRequestController::class, 'reject'])
        ->name('super.signup-requests.reject');

    Route::get('/contact-messages', [ContactMessageController::class, 'index'])->name('super.contact-messages.index');
    Route::patch('/contact-messages/{contactMessage}/read', [ContactMessageController::class, 'markRead'])
        ->name('super.contact-messages.read');
    Route::patch('/contact-messages/{contactMessage}/resolve', [ContactMessageController::class, 'resolve'])
        ->name('super.contact-messages.resolve');

    Route::get('/mobile-releases', [MobileAppReleaseController::class, 'index'])->name('super.mobile-releases.index');
    Route::post('/mobile-releases', [MobileAppReleaseController::class, 'store'])->name('super.mobile-releases.store');
    Route::put('/mobile-releases/{mobileRelease}', [MobileAppReleaseController::class, 'update'])->name('super.mobile-releases.update');
    Route::post('/mobile-releases/{mobileRelease}/publish', [MobileAppReleaseController::class, 'publish'])->name('super.mobile-releases.publish');
    Route::post('/mobile-releases/{mobileRelease}/unpublish', [MobileAppReleaseController::class, 'unpublish'])->name('super.mobile-releases.unpublish');
    Route::delete('/mobile-releases/{mobileRelease}', [MobileAppReleaseController::class, 'destroy'])->name('super.mobile-releases.destroy');
});
