export type AnalyticsType = 'dialogs' | 'funnels';

export type DialogsTab = 'overview' | 'dynamics' | 'team' | 'problems';

export type FunnelsTab = 'overview' | 'conversion' | 'coverage';

export type FilterOption = { id: number; name: string; department_id?: number | null };

export type FilterOptions = {
    departments: FilterOption[];
    employees: FilterOption[];
    sla_seconds: number;
    default_from: string;
    default_to: string;
};

export type RankingsPayload = {
    fastest_avg_response: Record<string, unknown>[];
    slowest_avg_response: Record<string, unknown>[];
    most_unanswered: Record<string, unknown>[];
    most_dialogs: Record<string, unknown>[];
    best_sla: Record<string, unknown>[];
    worst_sla: Record<string, unknown>[];
};

export type DialogSummary = {
    total_dialogs?: number;
    active_dialogs?: number;
    closed_dialogs?: number;
    avg_first_response_seconds?: number | null;
    avg_response_seconds?: number | null;
    max_client_wait_seconds?: number | null;
    unanswered_dialogs?: number;
    avg_idle_before_new_chat_seconds?: number | null;
    avg_time_to_close_seconds?: number | null;
    overdue_response_percent?: number | null;
    dialogs_per_staff_member?: number | null;
};

export type FunnelSummary = {
    total_funnels?: number;
    active_funnels?: number;
    connected_funnels?: number;
    total_stages?: number;
    selected_stages?: number;
    stage_coverage_percent?: number | null;
    departments_in_scope?: number;
    tracked_chats?: number;
    total_transitions?: number;
    funnels_with_conversion_data?: number;
};

export type EmployeeStatRow = {
    user_id: number;
    name: string;
    department_id?: number | null;
    department_ids?: number[];
    dialog_count: number;
    avg_response_seconds?: number | null;
    max_response_seconds?: number | null;
    unanswered_dialogs: number;
    closed_dialogs: number;
    avg_client_rating?: number | null;
    sla_on_time_percent?: number | null;
};

export type DepartmentStatRow = {
    department_id: number;
    name: string;
    dialog_count: number;
    avg_response_seconds?: number | null;
    max_delay_seconds?: number | null;
    active_dialogs: number;
    overdue_dialogs: number;
    best_employee_name?: string | null;
};

export type ProblematicChatRow = {
    chat_id: number;
    client_label: string;
    client_phone?: string | null;
    assignee_name?: string | null;
    department_name?: string | null;
    last_client_message_at?: string | null;
    wait_seconds?: number | null;
    status: string;
    open_url: string;
};

export type ChartData = {
    dialogs_over_time?: Array<{ date: string; count: number }>;
    avg_response_by_day?: Array<{ date: string; avg_seconds?: number | null }>;
    load_per_employee?: Array<{ name: string; dialogs: number }>;
    status_distribution?: { active?: number; closed?: number; waiting?: number };
};

export type FunnelStageRow = {
    id: number;
    name: string;
    color: string;
    selected?: boolean;
    is_active?: boolean;
    current_chats?: number;
    entries?: number;
    forward_exits?: number;
    conversion_percent?: number | null;
    drop_off?: number;
    is_final?: boolean;
    avg_hours_on_stage?: number | null;
    avg_response_minutes_ai?: number | null;
    avg_response_minutes_manager?: number | null;
    response_samples_ai?: number;
    response_samples_manager?: number;
};

export type FunnelConversionRow = {
    id: number;
    name: string;
    color: string;
    overall_conversion_percent?: number | null;
    stages: FunnelStageRow[];
};

export type FunnelCoverageRow = {
    id: number;
    name: string;
    color: string;
    description?: string | null;
    departments?: Array<{ id: number; name: string }>;
    stages_count: number;
    selected_stages_count: number;
    coverage_percent?: number | null;
    is_active: boolean;
    stages?: FunnelStageRow[];
};

export type DialogAnalyticsPayload = {
    sla_seconds?: number;
    summary: DialogSummary;
    employee_stats: EmployeeStatRow[];
    department_stats: DepartmentStatRow[];
    rankings: RankingsPayload;
    chart_data: ChartData;
    problematic_chats: {
        data: ProblematicChatRow[];
        meta: { current_page: number; last_page: number; per_page: number; total: number };
    };
};

export type FunnelAnalyticsPayload = {
    summary: FunnelSummary;
    funnels: FunnelCoverageRow[];
    conversion: { funnels: FunnelConversionRow[] } | null;
};

export type AnalyticsPayload = DialogAnalyticsPayload | FunnelAnalyticsPayload;

export type KpiTone = 'neutral' | 'success' | 'warning' | 'danger';

export type KpiItem = {
    label: string;
    value: string;
    tone?: KpiTone;
};

export type RankingBlockDef = {
    key: keyof RankingsPayload;
    titleKey: string;
    hintKey: string;
    secondaryKey?: string;
    primary: (r: Record<string, unknown>) => string;
    secondary?: (r: Record<string, unknown>) => string;
};
