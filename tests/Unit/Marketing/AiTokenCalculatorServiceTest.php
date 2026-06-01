<?php

declare(strict_types=1);

namespace Tests\Unit\Marketing;

use App\Services\Marketing\AiTokenCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AiTokenCalculatorServiceTest extends TestCase
{
    use RefreshDatabase;
    private AiTokenCalculatorService $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = app(AiTokenCalculatorService::class);
    }

    public function test_default_inputs_produce_positive_token_totals(): void
    {
        $result = $this->calculator->calculate(config('ai_calculator.defaults'));

        $this->assertGreaterThan(0, $result['totals']['input_tokens']);
        $this->assertGreaterThan(0, $result['totals']['output_tokens']);
        $this->assertGreaterThan(0, $result['totals']['api_cost_usd']);
        $this->assertGreaterThan(0, $result['totals']['api_cost_kzt']);
    }

    public function test_trigger_rates_affect_dept_routing_volume(): void
    {
        $lowRate = $this->calculator->calculate(config('ai_calculator.defaults'), [
            'trigger_rates' => ['dept_routing' => 0.1],
            'token_estimates' => [],
        ]);

        $highRate = $this->calculator->calculate(config('ai_calculator.defaults'), [
            'trigger_rates' => ['dept_routing' => 0.5],
            'token_estimates' => [],
        ]);

        $this->assertGreaterThan(
            $this->scenarioCalls($lowRate, 'dept_routing'),
            $this->scenarioCalls($highRate, 'dept_routing'),
        );
    }

    public function test_orchestrator_reduces_ai_reply_volume(): void
    {
        $base = $this->calculator->calculate([
            'leads_per_day' => 30,
            'inbound_msgs_per_lead' => 8,
            'ai_reply_rate' => 100,
            'orchestrator_rate' => 0,
            'funnel_enabled' => false,
            'silent_leads_per_day' => 0,
            'operators' => 1,
            'operator_ai_uses_per_day' => 0,
            'translations_per_day' => 0,
            'workspace_queries_per_day' => 0,
            'voice_msg_rate' => 0,
        ]);

        $withOrch = $this->calculator->calculate([
            'leads_per_day' => 30,
            'inbound_msgs_per_lead' => 8,
            'ai_reply_rate' => 100,
            'orchestrator_rate' => 50,
            'funnel_enabled' => false,
            'silent_leads_per_day' => 0,
            'operators' => 1,
            'operator_ai_uses_per_day' => 0,
            'translations_per_day' => 0,
            'workspace_queries_per_day' => 0,
            'voice_msg_rate' => 0,
        ]);

        $aiReplyBase = $this->scenarioCalls($base, 'ai_reply');
        $aiReplyOrch = $this->scenarioCalls($withOrch, 'ai_reply');
        $orchCalls = $this->scenarioCalls($withOrch, 'funnel_orchestrator');

        $this->assertGreaterThan(0, $orchCalls);
        $this->assertLessThan($aiReplyBase, $aiReplyOrch);
    }

    public function test_funnel_disabled_skips_classify_volume(): void
    {
        $enabled = $this->calculator->calculate([
            'leads_per_day' => 10,
            'funnel_enabled' => true,
            'silent_leads_per_day' => 0,
            'voice_msg_rate' => 0,
            'operator_ai_uses_per_day' => 0,
            'translations_per_day' => 0,
            'workspace_queries_per_day' => 0,
        ]);

        $disabled = $this->calculator->calculate([
            'leads_per_day' => 10,
            'funnel_enabled' => false,
            'silent_leads_per_day' => 0,
            'voice_msg_rate' => 0,
            'operator_ai_uses_per_day' => 0,
            'translations_per_day' => 0,
            'workspace_queries_per_day' => 0,
        ]);

        $this->assertGreaterThan(
            $this->scenarioCalls($disabled, 'funnel_classify'),
            $this->scenarioCalls($enabled, 'funnel_classify'),
        );
        $this->assertSame(0.0, $this->scenarioCalls($disabled, 'funnel_classify'));
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function scenarioCalls(array $result, string $id): float
    {
        foreach ($result['scenarios'] as $scenario) {
            if (($scenario['id'] ?? '') === $id) {
                return (float) ($scenario['calls'] ?? 0);
            }
        }

        return 0.0;
    }
}
