<?php

declare(strict_types=1);

namespace App\Services\AI\Locale;

final readonly class KazakhstanLocaleProfile
{
    public const DOMINANT_RU = 'ru';

    public const DOMINANT_KK = 'kk';

    public const DOMINANT_MIXED = 'mixed';

    public const DOMINANT_TRANSLIT_MIXED = 'translit_mixed';

    public const DOMINANT_UNKNOWN = 'unknown';

    public const FORMALITY_FORMAL = 'formal';

    public const FORMALITY_NEUTRAL = 'neutral';

    public const FORMALITY_CASUAL = 'casual';

    public const CONFIDENCE_HIGH = 'high';

    public const CONFIDENCE_LOW = 'low';

    public function __construct(
        public string $dominant,
        public float $ruPct,
        public float $kkPct,
        public string $script,
        public string $formality,
        public float $slangScore,
        public bool $allowMixedReply,
        public bool $preferKkCyrillic,
        public string $confidence,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'dominant' => $this->dominant,
            'ru_pct' => $this->ruPct,
            'kk_pct' => $this->kkPct,
            'script' => $this->script,
            'formality' => $this->formality,
            'slang_score' => $this->slangScore,
            'allow_mixed_reply' => $this->allowMixedReply,
            'prefer_kk_cyrillic' => $this->preferKkCyrillic,
            'confidence' => $this->confidence,
        ];
    }

    public static function neutralRussian(): self
    {
        return new self(
            dominant: self::DOMINANT_RU,
            ruPct: 1.0,
            kkPct: 0.0,
            script: 'cyrillic',
            formality: self::FORMALITY_NEUTRAL,
            slangScore: 0.0,
            allowMixedReply: false,
            preferKkCyrillic: false,
            confidence: self::CONFIDENCE_LOW,
        );
    }

    public function dominantLabel(): string
    {
        return match ($this->dominant) {
            self::DOMINANT_RU => 'русский',
            self::DOMINANT_KK => 'казахский',
            self::DOMINANT_MIXED => 'смешанный (русский + казахский)',
            self::DOMINANT_TRANSLIT_MIXED => 'транслит (латиница)',
            default => 'не определён',
        };
    }
}
