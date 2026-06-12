import type { ChartsPayload } from '@/Components/AiSales/charts/buildChartOptions';

export type Kpi = {
    key: string;
    label: string;
    percent: number | null;
    numerator: number;
    denominator: number;
    sufficient_data: boolean;
};

export type LostReason = {
    reason: string;
    count: number;
    percent: number;
};

export type WinRateGrade = {
    grade: string;
    won: number;
    total: number;
    percent: number | null;
};

export type ObjectionRow = {
    label: string;
    frequency: number;
    win_rate: number | null;
};

export type ObjectionResponseRow = {
    text: string;
    win_count?: number;
    loss_count?: number;
};

export type CompanyRow = {
    company_id: number;
    company_name: string;
    company_slug: string;
    cohort_size: number;
    closed_deals: number;
    qualification_rate: number | null;
    budget_capture_rate: number | null;
    close_rate: number | null;
    meeting_booking_rate: number | null;
};

export type CompanyOption = {
    id: number;
    name: string;
    slug: string;
};

export type ExperimentRow = {
    experiment_id: number;
    experiment_name: string;
    variant_key: string;
    is_control: boolean;
    replies: number;
    qualified: number;
    closed_won: number;
    close_rate: number | null;
};

export type AiSalesMetricsPayload = {
    period: { from: string; to: string };
    filters: { company_id: number | null; company_name: string | null };
    summary: { cohort_size: number; closed_deals: number; follow_ups_sent: number };
    kpis: Kpi[];
    lost_reasons: LostReason[];
    win_rate_by_grade: WinRateGrade[];
    objection_intelligence: {
        top_objections: ObjectionRow[];
        top_winning_responses: ObjectionResponseRow[];
        top_losing_responses: ObjectionResponseRow[];
    };
    by_company: CompanyRow[];
    experiments?: ExperimentRow[];
    win_prob_model?: { type: string; version: number | null } | null;
    charts: ChartsPayload;
};

export type { ChartsPayload };
