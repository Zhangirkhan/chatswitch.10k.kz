<?php

declare(strict_types=1);

namespace App\Services\AI\Orchestrator;

final readonly class ClientSituation
{
    public const SITUATION_NONE = 'none';

    public const SITUATION_DELAY = 'delay';

    public const SITUATION_QUALITY = 'quality_issue';

    public const SITUATION_REFUND = 'refund';

    public const SITUATION_SCAM_ACCUSATION = 'scam_accusation';

    public const SITUATION_AGGRESSION = 'aggression';

    public const SITUATION_LEGAL = 'legal_threat';

    public const SITUATION_CONFUSION = 'confusion_repeat';

    public const SITUATION_OFF_TOPIC = 'off_topic';

    public const SITUATION_PRICE_PRESSURE = 'price_pressure';

    public const SITUATION_PASSIVE_AGGRESSIVE = 'passive_aggressive';

    public const SITUATION_COMPLAINT = 'complaint';

    /**
     * @param  list<string>  $signals
     */
    public function __construct(
        public string $situation,
        public int $tier,
        public float $confidence,
        public array $signals = [],
    ) {}

    public function isConflict(): bool
    {
        return $this->tier >= 1 && $this->situation !== self::SITUATION_NONE;
    }

    public static function none(): self
    {
        return new self(self::SITUATION_NONE, 0, 0.0);
    }
}
