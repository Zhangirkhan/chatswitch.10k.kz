import type { ChartSeriesLabels, FunnelStageLabels } from '@/Components/AiSales/charts/buildChartOptions';
import { useI18n } from '@/composables/useI18n';
import { computed, type ComputedRef } from 'vue';

export function useAiSalesChartLabels(i18nPrefix: string): {
    seriesLabels: ComputedRef<ChartSeriesLabels>;
    funnelStageLabels: ComputedRef<FunnelStageLabels>;
} {
    const { t } = useI18n();

    const seriesLabels = computed<ChartSeriesLabels>(() => ({
        won: t(`${i18nPrefix}.chartSeriesWon`),
        lost: t(`${i18nPrefix}.chartSeriesLost`),
        winRate: t(`${i18nPrefix}.chartSeriesWinRate`),
        count: t(`${i18nPrefix}.chartSeriesCount`),
        replies: t(`${i18nPrefix}.experimentReplies`),
        qualified: t(`${i18nPrefix}.experimentQualified`),
        predicted: t(`${i18nPrefix}.chartSeriesPredicted`),
        actual: t(`${i18nPrefix}.chartSeriesActual`),
    }));

    const funnelStageLabels = computed<FunnelStageLabels>(() => ({
        qualification_rate: t(`${i18nPrefix}.funnelStageQualification`),
        budget_capture_rate: t(`${i18nPrefix}.funnelStageBudget`),
        proposal_rate: t(`${i18nPrefix}.funnelStageProposal`),
        meeting_booking_rate: t(`${i18nPrefix}.funnelStageMeeting`),
        close_rate: t(`${i18nPrefix}.funnelStageClose`),
    }));

    return { seriesLabels, funnelStageLabels };
}
