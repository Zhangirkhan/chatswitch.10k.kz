<script setup lang="ts">
import { useTheme } from '@/composables/useTheme';
import {
    ArcElement,
    BarController,
    BarElement,
    CategoryScale,
    Chart as ChartJS,
    DoughnutController,
    Legend,
    LineController,
    LineElement,
    LinearScale,
    PieController,
    PointElement,
    Tooltip,
} from 'chart.js';
import { useI18n } from '@/composables/useI18n';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { Bar, Doughnut, Line, Pie } from 'vue-chartjs';
import { repairMermaidCode, stripMermaidFences } from '@/utils/mermaidCode';

export type AiVisualization = {
    id: string;
    type: 'chart' | 'mermaid';
    title?: string | null;
    chart_type?: 'bar' | 'line' | 'doughnut' | 'pie';
    labels?: string[];
    datasets?: Array<{ label: string; data: number[] }>;
    code?: string;
};

ChartJS.register(
    ArcElement,
    BarController,
    BarElement,
    CategoryScale,
    DoughnutController,
    Legend,
    LinearScale,
    LineController,
    LineElement,
    PieController,
    PointElement,
    Tooltip,
);

const props = defineProps<{
    item: AiVisualization;
}>();

const { t } = useI18n();

const { theme } = useTheme();
const mermaidHost = ref<HTMLElement | null>(null);
const mermaidError = ref<string | null>(null);
let renderSeq = 0;
let mermaidApi: typeof import('mermaid').default | null = null;

async function loadMermaid(): Promise<typeof import('mermaid').default> {
    if (mermaidApi) {
        return mermaidApi;
    }

    const mod = await import('mermaid');
    mermaidApi = mod.default;

    return mermaidApi;
}

function configureMermaid(mermaid: typeof import('mermaid').default): void {
    mermaid.initialize({
        startOnLoad: false,
        theme: theme.value === 'light' ? 'neutral' : 'dark',
        securityLevel: 'loose',
        fontFamily: 'Figtree, system-ui, sans-serif',
        flowchart: {
            htmlLabels: false,
        },
    });
}

async function renderMermaid(): Promise<void> {
    if (props.item.type !== 'mermaid' || !mermaidHost.value || !props.item.code) {
        return;
    }

    const seq = ++renderSeq;
    mermaidError.value = null;

    const code = repairMermaidCode(stripMermaidFences(props.item.code));
    const host = mermaidHost.value;
    host.innerHTML = '';

    const pre = document.createElement('pre');
    pre.className = 'mermaid';
    pre.textContent = code;
    host.appendChild(pre);

    try {
        const mermaid = await loadMermaid();
        configureMermaid(mermaid);

        await mermaid.run({
            nodes: [pre],
            suppressErrors: false,
        });

        if (seq !== renderSeq) {
            return;
        }
    } catch {
        if (seq !== renderSeq) {
            return;
        }

        mermaidError.value = t('aiChat.mermaidError');
        host.innerHTML = '';
    }
}

const accent = '#01b964';
const palette = ['#01b964', '#34d399', '#22d3ee', '#6366f1', '#f59e0b', '#ef4444', '#ec4899', '#84cc16'];

const chartLegendColor = computed(() => (theme.value === 'light' ? '#334155' : '#e2e8f0'));
const chartTickColor = computed(() => (theme.value === 'light' ? '#64748b' : '#94a3b8'));
const chartGridColor = computed(() =>
    theme.value === 'light' ? 'rgba(15, 23, 42, 0.08)' : 'rgba(148, 163, 184, 0.14)',
);

const chartData = computed(() => {
    const labels = props.item.labels ?? [];
    const datasets = (props.item.datasets ?? []).map((ds, index) => ({
        label: ds.label,
        data: ds.data,
        backgroundColor:
            props.item.chart_type === 'line'
                ? `${accent}55`
                : labels.map((_, i) => palette[(index + i) % palette.length]),
        borderColor:
            props.item.chart_type === 'line'
                ? accent
                : labels.map((_, i) => palette[(index + i) % palette.length]),
        borderWidth: props.item.chart_type === 'line' ? 2 : 1,
        tension: 0.35,
        fill: props.item.chart_type === 'line',
    }));

    return { labels, datasets };
});

const chartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: {
            display: (props.item.datasets?.length ?? 0) > 1 || props.item.chart_type === 'doughnut' || props.item.chart_type === 'pie',
            labels: { color: chartLegendColor.value, boxWidth: 12 },
        },
        tooltip: {
            enabled: true,
        },
    },
    scales:
        props.item.chart_type === 'doughnut' || props.item.chart_type === 'pie'
            ? undefined
            : {
                  x: {
                      ticks: { color: chartTickColor.value, maxRotation: 0, autoSkip: true },
                      grid: { color: chartGridColor.value },
                  },
                  y: {
                      ticks: { color: chartTickColor.value, precision: 0 },
                      grid: { color: chartGridColor.value },
                      beginAtZero: true,
                  },
              },
}));

watch(
    () => [props.item.id, props.item.code, theme.value],
    () => {
        mermaidApi = null;
        void nextTick(() => renderMermaid());
    },
);

onMounted(() => {
    void renderMermaid();
});
</script>

<template>
    <div class="ai-viz">
        <h4 v-if="item.title" class="ai-viz__title">{{ item.title }}</h4>

        <div v-if="item.type === 'chart'" class="ai-viz__chart-wrap">
            <Bar
                v-if="item.chart_type === 'bar'"
                :data="chartData"
                :options="chartOptions"
            />
            <Line
                v-else-if="item.chart_type === 'line'"
                :data="chartData"
                :options="chartOptions"
            />
            <Doughnut
                v-else-if="item.chart_type === 'doughnut'"
                :data="chartData"
                :options="chartOptions"
            />
            <Pie
                v-else
                :data="chartData"
                :options="chartOptions"
            />
        </div>

        <div v-else class="ai-viz__mermaid-wrap">
            <div ref="mermaidHost" class="ai-viz__mermaid"></div>
            <p v-if="mermaidError" class="ai-viz__error">{{ mermaidError }}</p>
        </div>
    </div>
</template>

<style scoped>
.ai-viz {
    margin-top: 10px;
    border-radius: 14px;
    border: 1px solid var(--wa-control-rim);
    background: var(--wa-panel);
    box-shadow: var(--wa-control-rim-shadow);
    overflow: hidden;
}

.ai-viz__title {
    padding: 10px 12px 0;
    font-size: 0.75rem;
    font-weight: 650;
    color: var(--wa-text-secondary);
}

.ai-viz__chart-wrap {
    height: 240px;
    padding: 8px 10px 12px;
}

.ai-viz__mermaid-wrap {
    padding: 10px 12px 12px;
    overflow-x: auto;
}

.ai-viz__mermaid :deep(svg) {
    display: block;
    max-width: 100%;
    height: auto;
    margin: 0 auto;
}

.ai-viz__error {
    margin: 8px 0 0;
    font-size: 0.75rem;
    color: var(--wa-chroma-critical-fg);
}
</style>
