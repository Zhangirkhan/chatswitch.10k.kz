<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\ReminderLeadTimeParser;
use PHPUnit\Framework\TestCase;

final class ReminderLeadTimeParserTest extends TestCase
{
    private ReminderLeadTimeParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new ReminderLeadTimeParser;
    }

    public function test_parses_two_hours_before_visit(): void
    {
        $this->assertSame(120, $this->parser->parseFromText('Запишите на завтра в 15:00, предупредите за 2 часа'));
    }

    public function test_parses_half_hour_before_visit(): void
    {
        $this->assertSame(30, $this->parser->parseFromText('Да, подходит. Напомните за полчаса, пожалуйста'));
    }

    public function test_parses_minutes_before_visit(): void
    {
        $this->assertSame(45, $this->parser->parseFromText('Можно записать и напомнить за 45 минут до визита'));
    }

    public function test_returns_null_without_reminder_context(): void
    {
        $this->assertNull($this->parser->parseFromText('Запишите меня на завтра в 13:00'));
    }
}
