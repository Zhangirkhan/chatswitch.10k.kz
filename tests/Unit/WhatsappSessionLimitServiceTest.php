<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\WhatsappSessionLimitService;
use Tests\TestCase;

final class WhatsappSessionLimitServiceTest extends TestCase
{
    public function test_global_max_defaults_from_server_ram_formula(): void
    {
        config([
            'whatsapp.max_sessions_global' => 20,
            'whatsapp.max_sessions_per_tenant' => 20,
        ]);

        $service = app(WhatsappSessionLimitService::class);

        $this->assertSame(20, $service->globalMax());
        $this->assertSame(20, $service->perTenantMax());
    }
}
