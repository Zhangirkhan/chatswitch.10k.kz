<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\AssistantClientDraftExtractor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AssistantClientDraftExtractorTest extends TestCase
{
    private AssistantClientDraftExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new AssistantClientDraftExtractor;
    }

    #[Test]
    public function it_extracts_draft_after_luchshe_tak_intro(): void
    {
        $content = <<<'TEXT'
Лучше так:
«Здравствуйте! Извините, пожалуйста, что сразу не поприветствовали вас. У нас доступны: индивидуальный заказ, консультация, расчёт стоимости и доставка/выполнение. Подскажите, пожалуйста, что вас интересует — постараюсь помочь.»
Так звучит спокойнее, вежливее и не акцентирует ошибку словом «невнимательность».
TEXT;

        $draft = $this->extractor->extract($content);

        $this->assertSame(
            'Здравствуйте! Извините, пожалуйста, что сразу не поприветствовали вас. У нас доступны: индивидуальный заказ, консультация, расчёт стоимости и доставка/выполнение. Подскажите, пожалуйста, что вас интересует — постараюсь помочь.',
            $draft,
        );
    }

    #[Test]
    public function it_uses_first_variant_when_variants_present(): void
    {
        $variantPayload = [
            'intro' => 'Intro',
            'variants' => [
                ['label' => '1', 'text' => 'Первый вариант'],
                ['label' => '2', 'text' => 'Второй вариант'],
            ],
        ];

        $draft = $this->extractor->extract('ignored body', $variantPayload);

        $this->assertSame('Первый вариант', $draft);
    }

    #[Test]
    public function it_picks_longest_guillemet_block_without_intro(): void
    {
        $content = 'Клиент написал «Привет». Ответ: «Добрый день! Чем могу помочь?»';

        $draft = $this->extractor->extract($content);

        $this->assertSame('Добрый день! Чем могу помочь?', $draft);
    }

    #[Test]
    public function it_strips_outer_quotes_from_plain_reply(): void
    {
        $this->assertSame(
            'Здравствуйте! Чем могу помочь?',
            $this->extractor->extract('«Здравствуйте! Чем могу помочь?»'),
        );
    }

    #[Test]
    public function it_returns_plain_text_when_no_structure(): void
    {
        $this->assertSame(
            'Здравствуйте! Чем могу помочь?',
            $this->extractor->extract('Здравствуйте! Чем могу помочь?'),
        );
    }
}
