<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\AssistantReplyVariantParser;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AssistantReplyVariantParserTest extends TestCase
{
    private AssistantReplyVariantParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new AssistantReplyVariantParser;
    }

    #[Test]
    public function it_parses_russian_variants_with_guillemets(): void
    {
        $content = <<<'TEXT'
Последняя реплика клиента — «Здравствуйте!»; у него нет явного запроса/вопроса.

Вариант 1: «Подскажите, пожалуйста, что вас интересует?»
Вариант 2: «Здравствуйте! Да, на связи. Чем могу помочь?»
TEXT;

        $parsed = $this->parser->parse($content);

        $this->assertNotNull($parsed);
        $this->assertStringContainsString('Последняя реплика клиента', $parsed['intro']);
        $this->assertCount(2, $parsed['variants']);
        $this->assertSame('Подскажите, пожалуйста, что вас интересует?', $parsed['variants'][0]['text']);
        $this->assertSame('Здравствуйте! Да, на связи. Чем могу помочь?', $parsed['variants'][1]['text']);
    }

    #[Test]
    public function it_parses_list_prefixed_variants(): void
    {
        $content = "- **Вариант 1:** «Hello»\n- **Вариант 2:** «Hi»";

        $parsed = $this->parser->parse($content);

        $this->assertNotNull($parsed);
        $this->assertCount(2, $parsed['variants']);
        $this->assertSame('Hello', $parsed['variants'][0]['text']);
    }

    #[Test]
    public function it_parses_numbered_list_variants(): void
    {
        $content = <<<'TEXT'
Клиент спрашивает про доставку в Караганду.
1. Аалымжан, доставка до Караганде бесплатная — привезём на ваш адрес.
2. По Караганде до адреса доставляем бесплатно. Напишите адрес и удобное время.
TEXT;

        $parsed = $this->parser->parse($content);

        $this->assertNotNull($parsed);
        $this->assertStringContainsString('Караганду', $parsed['intro']);
        $this->assertCount(2, $parsed['variants']);
        $this->assertStringContainsString('Аалымжан', $parsed['variants'][0]['text']);
        $this->assertStringContainsString('По Караганде', $parsed['variants'][1]['text']);
    }

    #[Test]
    public function it_returns_null_without_variants(): void
    {
        $this->assertNull($this->parser->parse('Просто текст без вариантов.'));
    }
}
