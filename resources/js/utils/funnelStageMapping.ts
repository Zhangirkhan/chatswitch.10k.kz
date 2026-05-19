export type FunnelStageRef = {
    id: number;
    position: number;
};

export type FunnelCatalogEntry = {
    id: number;
    stages: FunnelStageRef[];
};

export function orderedFunnelStages(stages: FunnelStageRef[]): FunnelStageRef[] {
    return [...stages].sort((a, b) => a.position - b.position);
}

export function stageIndexInFunnel(
    funnel: FunnelCatalogEntry | undefined,
    stageId: number | null,
): number {
    if (!funnel?.stages?.length || stageId == null) {
        return -1;
    }

    return orderedFunnelStages(funnel.stages).findIndex((stage) => stage.id === stageId);
}

/** При смене воронки сохраняем порядковый номер этапа (1-й → 1-й, 3-й → 3-й или последний). */
export function stageIdAtPreservedIndex(
    fromFunnel: FunnelCatalogEntry | undefined,
    fromStageId: number | null,
    toFunnel: FunnelCatalogEntry | undefined,
): number | null {
    if (!toFunnel?.stages?.length) {
        return null;
    }

    const toOrdered = orderedFunnelStages(toFunnel.stages);
    const fromIndex = stageIndexInFunnel(fromFunnel, fromStageId);
    const targetIndex = fromIndex >= 0 ? fromIndex : 0;

    return toOrdered[Math.min(targetIndex, toOrdered.length - 1)]!.id;
}
