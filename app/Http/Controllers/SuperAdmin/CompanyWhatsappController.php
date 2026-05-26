<?php

declare(strict_types=1);

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\WhatsappSession;
use App\Services\WhatsappService;
use Illuminate\Http\JsonResponse;

final class CompanyWhatsappController extends Controller
{
    public function __construct(
        private readonly WhatsappService $whatsapp,
    ) {}

    public function qr(Company $company, WhatsappSession $session): JsonResponse
    {
        $this->assertSessionBelongsToCompany($company, $session);

        if (! $this->whatsapp->healthReachable()) {
            return response()->json([
                'success' => false,
                'message' => 'Сервис WhatsApp недоступен.',
            ], 503);
        }

        $result = $this->whatsapp->getSessionQR($session->session_name);

        return response()->json($result);
    }

    public function status(Company $company, WhatsappSession $session): JsonResponse
    {
        $this->assertSessionBelongsToCompany($company, $session);

        if (! $this->whatsapp->healthReachable()) {
            return response()->json([
                'success' => false,
                'message' => 'Сервис WhatsApp недоступен.',
            ], 503);
        }

        $result = $this->whatsapp->getSessionStatus($session->session_name);

        return response()->json($result);
    }

    private function assertSessionBelongsToCompany(Company $company, WhatsappSession $session): void
    {
        abort_unless((int) $session->company_id === (int) $company->id, 404);
    }
}
