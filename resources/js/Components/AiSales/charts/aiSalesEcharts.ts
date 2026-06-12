import { BarChart, FunnelChart, LineChart, PieChart } from 'echarts/charts';
import {
    DatasetComponent,
    GridComponent,
    LegendComponent,
    TooltipComponent,
} from 'echarts/components';
import * as echarts from 'echarts/core';
import { CanvasRenderer } from 'echarts/renderers';
import VChart from 'vue-echarts';

let registered = false;

export function ensureAiSalesEchartsRegistered(): void {
    if (registered) {
        return;
    }

    echarts.use([
        BarChart,
        LineChart,
        PieChart,
        FunnelChart,
        GridComponent,
        TooltipComponent,
        LegendComponent,
        DatasetComponent,
        CanvasRenderer,
    ]);

    registered = true;
}

export { VChart };
