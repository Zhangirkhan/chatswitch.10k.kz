<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use App\Services\AI\Orchestrator\ClientSituation;
use App\Services\AI\Orchestrator\ClientSituationDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ClientSituationDetectorTest extends TestCase
{
    private ClientSituationDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = app(ClientSituationDetector::class);
    }

    #[DataProvider('scenarioProvider')]
    public function test_detects_scenarios(string $body, string $expectedSituation, int $expectedTier): void
    {
        $result = $this->detector->detect($body);

        $this->assertSame($expectedSituation, $result->situation);
        $this->assertSame($expectedTier, $result->tier);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function scenarioProvider(): array
    {
        return [
            'delay' => ['Где мой заказ?! Обещали в пятницу!!!', ClientSituation::SITUATION_DELAY, 1],
            'quality' => ['Дверь с царапиной, вы что прислали?', ClientSituation::SITUATION_QUALITY, 1],
            'refund' => ['Верните деньги, Kaspi оспорю', ClientSituation::SITUATION_REFUND, 2],
            'scam' => ['Вы мошенники, развод на предоплату', ClientSituation::SITUATION_SCAM_ACCUSATION, 2],
            'aggression_caps' => ['ВЫ ИДИОТЫ!!!', ClientSituation::SITUATION_AGGRESSION, 2],
            'positive_thanks' => ['добрый вечер, помидоры получил. очень вкусно спасибо большое!!! буду еще заказывать', ClientSituation::SITUATION_NONE, 0],
            'legal' => ['Подам в суд и в прокуратуру', ClientSituation::SITUATION_LEGAL, 3],
            'complaint' => ['Я очень недоволен качеством', ClientSituation::SITUATION_COMPLAINT, 1],
            'price_pressure' => ['У конкурентов на 30% дешевле', ClientSituation::SITUATION_PRICE_PRESSURE, 1],
            'passive' => ['Ну конечно, как всегда у вас…', ClientSituation::SITUATION_PASSIVE_AGGRESSIVE, 1],
            'confusion' => ['???', ClientSituation::SITUATION_CONFUSION, 0],
            'normal' => ['Здравствуйте, сколько стоит дверь?', ClientSituation::SITUATION_NONE, 0],
        ];
    }
}
