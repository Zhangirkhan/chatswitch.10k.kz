<?php

declare(strict_types=1);

namespace Tests\Unit\Marketing;

use App\Models\AiUsageEvent;
use App\Services\Marketing\AiCalculatorBenchmarkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AiCalculatorBenchmarkServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_aggregates_measured_token_estimates(): void
    {
        config(['ai_calculator.benchmark.min_samples' => 5]);

        for ($i = 0; $i < 10; $i++) {
            AiUsageEvent::query()->create([
                'scenario' => 'ai_reply',
                'kind' => 'chat',
                'tokens_input' => 6000,
                'tokens_output' => 300,
            ]);
        }

        $benchmarks = app(AiCalculatorBenchmarkService::class)->benchmarks();

        $this->assertTrue($benchmarks['token_estimates']['ai_reply']['measured']);
        $this->assertSame(6000, $benchmarks['token_estimates']['ai_reply']['input_tokens']);
        $this->assertSame(300, $benchmarks['token_estimates']['ai_reply']['output_tokens']);
    }
}
