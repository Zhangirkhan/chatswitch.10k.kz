export type CalculatorInputs = {
    leads_per_day: number;
    inbound_msgs_per_lead: number;
    ai_reply_rate: number;
    funnel_enabled: boolean;
    orchestrator_rate: number;
    voice_msg_rate: number;
    avg_voice_duration_sec: number;
    silent_leads_per_day: number;
    operators: number;
    operator_ai_uses_per_day: number;
    translations_per_day: number;
    workspace_queries_per_day: number;
    work_days_per_month: number;
};

export type ScenarioConfig = {
    id: string;
    label: string;
    description: string;
    volume_key: string;
    input_tokens: number;
    output_tokens: number;
    type: 'chat' | 'embedding' | 'whisper' | 'fixed_usd';
    tokens_measured?: boolean;
};

export type PricingConfig = {
    'gpt-4o-mini': { input_per_1m: number; output_per_1m: number };
    'text-embedding-3-small': { per_1m: number };
    whisper: { per_minute: number };
    usd_to_kzt: number;
};

export type ExchangeRate = {
    rate: number;
    date: string;
    source: string;
};

export type BenchmarkMeta = {
    period_days: number;
    trigger_rates: Record<string, number>;
    has_measurements: boolean;
};

export type ScenarioResult = {
    id: string;
    label: string;
    description: string;
    type: string;
    calls: number;
    input_tokens: number;
    output_tokens: number;
    embedding_tokens: number;
    cost_usd: number;
    tokens_measured?: boolean;
};

export type CalculatorResult = {
    inbound_per_month: number;
    ai_inbound_per_month: number;
    scenarios: ScenarioResult[];
    totals: {
        input_tokens: number;
        output_tokens: number;
        embedding_tokens: number;
        whisper_minutes: number;
        api_cost_usd: number;
        api_cost_kzt: number;
        subscription_kzt: number;
    };
};

function clampPercent(value: number): number {
    return Math.max(0, Math.min(100, value));
}

export function computeVolumes(
    inputs: CalculatorInputs,
    triggerRates: Record<string, number> = {},
): Record<string, number> {
    const inboundPerDay = inputs.leads_per_day * inputs.inbound_msgs_per_lead;
    const inboundPerMonth = inboundPerDay * inputs.work_days_per_month;
    const aiInboundPerMonth = Math.round(inboundPerMonth * (inputs.ai_reply_rate / 100));
    const orchFraction = inputs.orchestrator_rate / 100;

    const voiceMsgsPerMonth = inboundPerMonth * (inputs.voice_msg_rate / 100);
    const whisperMinutes = (voiceMsgsPerMonth * inputs.avg_voice_duration_sec) / 60;

    const deptRate = triggerRates.dept_routing ?? 0.3;
    const appointmentRate = triggerRates.appointment_intent ?? 0.15;
    const historyRate = triggerRates.history_compress ?? 0.1;
    const ragRate = triggerRates.rag_embed ?? 2;
    const funnelClassifyRate = triggerRates.funnel_classify ?? 1;
    const autoFollowUpRate = triggerRates.auto_follow_up ?? 0.5;

    return {
        inbound_per_month: inboundPerMonth,
        ai_inbound_per_month: aiInboundPerMonth,
        ai_reply: aiInboundPerMonth * (1 - orchFraction),
        dept_routing: inboundPerMonth * deptRate,
        appointment_intent: aiInboundPerMonth * appointmentRate,
        history_compress: aiInboundPerMonth * historyRate,
        rag_embed: aiInboundPerMonth * ragRate,
        funnel_classify: inputs.funnel_enabled ? inboundPerMonth * funnelClassifyRate : 0,
        funnel_orchestrator: aiInboundPerMonth * orchFraction,
        follow_up_proposal: inputs.silent_leads_per_day * inputs.work_days_per_month,
        auto_follow_up: inputs.silent_leads_per_day * inputs.work_days_per_month * autoFollowUpRate,
        operator_assistant:
            inputs.operators * inputs.operator_ai_uses_per_day * inputs.work_days_per_month,
        translation: inputs.translations_per_day * inputs.work_days_per_month,
        workspace_query:
            inputs.operators * inputs.workspace_queries_per_day * inputs.work_days_per_month,
        whisper_minutes: whisperMinutes,
        background: 1,
    };
}

export function calculateTokenUsage(
    inputs: CalculatorInputs,
    scenarios: ScenarioConfig[],
    pricing: PricingConfig,
    backgroundMonthlyUsd: number,
    subscriptionKzt: number,
    exchangeRate: ExchangeRate,
    triggerRates: Record<string, number> = {},
): CalculatorResult {
    const volumes = computeVolumes(inputs, triggerRates);
    const gptIn = pricing['gpt-4o-mini'].input_per_1m;
    const gptOut = pricing['gpt-4o-mini'].output_per_1m;
    const embedPer1m = pricing['text-embedding-3-small'].per_1m;
    const whisperPerMin = pricing.whisper.per_minute;
    const usdToKzt = exchangeRate.rate;

    let totalInput = 0;
    let totalOutput = 0;
    let totalEmbed = 0;
    let totalUsd = 0;
    let whisperMinutes = 0;

    const scenarioResults: ScenarioResult[] = scenarios.map((scenario) => {
        let calls = volumes[scenario.volume_key] ?? 0;
        let inputTokens = 0;
        let outputTokens = 0;
        let embeddingTokens = 0;
        let costUsd = 0;

        if (scenario.type === 'chat' && calls > 0) {
            inputTokens = Math.round(calls * scenario.input_tokens);
            outputTokens = Math.round(calls * scenario.output_tokens);
            costUsd =
                (inputTokens / 1_000_000) * gptIn + (outputTokens / 1_000_000) * gptOut;
        } else if (scenario.type === 'embedding' && calls > 0) {
            embeddingTokens = Math.round(calls * scenario.input_tokens);
            costUsd = (embeddingTokens / 1_000_000) * embedPer1m;
        } else if (scenario.type === 'whisper' && calls > 0) {
            whisperMinutes = calls;
            costUsd = whisperMinutes * whisperPerMin;
        } else if (scenario.type === 'fixed_usd') {
            calls = 1;
            costUsd = backgroundMonthlyUsd;
        }

        totalInput += inputTokens;
        totalOutput += outputTokens;
        totalEmbed += embeddingTokens;
        totalUsd += costUsd;

        return {
            id: scenario.id,
            label: scenario.label,
            description: scenario.description,
            type: scenario.type,
            calls: Math.round(calls * 100) / 100,
            input_tokens: inputTokens,
            output_tokens: outputTokens,
            embedding_tokens: embeddingTokens,
            cost_usd: Math.round(costUsd * 10000) / 10000,
            tokens_measured: scenario.tokens_measured,
        };
    });

    const apiCostUsd = Math.round(totalUsd * 100) / 100;

    return {
        inbound_per_month: volumes.inbound_per_month,
        ai_inbound_per_month: volumes.ai_inbound_per_month,
        scenarios: scenarioResults,
        totals: {
            input_tokens: totalInput,
            output_tokens: totalOutput,
            embedding_tokens: totalEmbed,
            whisper_minutes: Math.round(whisperMinutes * 10) / 10,
            api_cost_usd: apiCostUsd,
            api_cost_kzt: Math.round(apiCostUsd * usdToKzt),
            subscription_kzt: subscriptionKzt,
        },
    };
}

export function normalizeInputs(raw: Partial<CalculatorInputs>, defaults: CalculatorInputs): CalculatorInputs {
    return {
        leads_per_day: Math.max(1, Math.min(500, raw.leads_per_day ?? defaults.leads_per_day)),
        inbound_msgs_per_lead: Math.max(1, Math.min(50, raw.inbound_msgs_per_lead ?? defaults.inbound_msgs_per_lead)),
        ai_reply_rate: clampPercent(raw.ai_reply_rate ?? defaults.ai_reply_rate),
        funnel_enabled: raw.funnel_enabled ?? defaults.funnel_enabled,
        orchestrator_rate: clampPercent(raw.orchestrator_rate ?? defaults.orchestrator_rate),
        voice_msg_rate: clampPercent(raw.voice_msg_rate ?? defaults.voice_msg_rate),
        avg_voice_duration_sec: Math.max(5, Math.min(120, raw.avg_voice_duration_sec ?? defaults.avg_voice_duration_sec)),
        silent_leads_per_day: Math.max(0, Math.min(100, raw.silent_leads_per_day ?? defaults.silent_leads_per_day)),
        operators: Math.max(1, Math.min(50, raw.operators ?? defaults.operators)),
        operator_ai_uses_per_day: Math.max(0, Math.min(50, raw.operator_ai_uses_per_day ?? defaults.operator_ai_uses_per_day)),
        translations_per_day: Math.max(0, Math.min(100, raw.translations_per_day ?? defaults.translations_per_day)),
        workspace_queries_per_day: Math.max(0, Math.min(50, raw.workspace_queries_per_day ?? defaults.workspace_queries_per_day)),
        work_days_per_month: Math.max(20, Math.min(31, raw.work_days_per_month ?? defaults.work_days_per_month)),
    };
}

export function formatNumber(value: number): string {
    return new Intl.NumberFormat('ru-RU').format(value);
}

export function formatUsd(value: number): string {
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', minimumFractionDigits: 2 }).format(value);
}

export function formatKzt(value: number): string {
    return `${formatNumber(Math.round(value))} ₸`;
}

export function formatExchangeRate(exchange: ExchangeRate): string {
    const rate = formatNumber(Math.round(exchange.rate * 100) / 100);
    if (exchange.source === 'nbk') {
        return `Курс НБ РК: ${rate} ₸/$ на ${exchange.date}`;
    }

    return `Курс: ${rate} ₸/$`;
}
