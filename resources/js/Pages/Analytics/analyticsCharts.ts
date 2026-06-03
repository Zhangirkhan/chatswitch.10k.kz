import {
    ArcElement,
    BarController,
    BarElement,
    CategoryScale,
    Chart as ChartJS,
    DoughnutController,
    Legend,
    LinearScale,
    LineController,
    LineElement,
    PointElement,
    Tooltip,
} from 'chart.js';

let registered = false;

export function ensureAnalyticsChartsRegistered(): void {
    if (registered) {
        return;
    }

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
        PointElement,
        Tooltip,
    );

    registered = true;
}
