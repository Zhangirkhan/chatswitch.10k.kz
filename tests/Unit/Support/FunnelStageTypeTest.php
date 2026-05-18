<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\FunnelStageType;
use PHPUnit\Framework\TestCase;

final class FunnelStageTypeTest extends TestCase
{
    public function test_guess_from_russian_stage_names(): void
    {
        $this->assertSame(FunnelStageType::LEAD, FunnelStageType::guessFromName('Первичный запрос'));
        $this->assertSame(FunnelStageType::QUALIFICATION, FunnelStageType::guessFromName('Замер / консультация'));
        $this->assertSame(FunnelStageType::OFFER, FunnelStageType::guessFromName('КП отправлено'));
        $this->assertSame(FunnelStageType::PAYMENT, FunnelStageType::guessFromName('Ожидание оплаты'));
        $this->assertSame(FunnelStageType::PRODUCTION, FunnelStageType::guessFromName('В работе'));
        $this->assertSame(FunnelStageType::DELIVERY, FunnelStageType::guessFromName('Доставка / монтаж'));
        $this->assertSame(FunnelStageType::DONE, FunnelStageType::guessFromName('Закрыто успешно'));
    }
}
