/**
 * Chart.js wrappers as Alpine components. Each chart is destroyed with its
 * element, so Livewire re-renders and wire:navigate don't leak canvases.
 *
 * Chart.js (~200 KB) is loaded the first time a chart appears, not with every
 * page: most pages have no chart.
 */
let chartJs = null;

function loadChart() {
    chartJs ??= import('chart.js').then(({ Chart, registerables }) => {
        Chart.register(...registerables);

        return Chart;
    });

    return chartJs;
}
const COLORS = {
    onTime: '#16a34a',
    late: '#d97706',
    undertime: '#f97316',
    lateUndertime: '#dc2626',
    absent: '#dc2626',
    muted: '#9ca3af',
    blue: '#2563eb',
    blueLight: '#93c5fd',
    target: '#6366f1',
};

const STATUS_COLORS = {
    on_time: COLORS.onTime,
    late: COLORS.late,
    undertime: COLORS.undertime,
    late_and_undertime: COLORS.lateUndertime,
};

function baseOptions(extra = {}) {
    const dark = document.documentElement.classList.contains('dark');
    const text = dark ? '#9ca3af' : '#6b7280';
    const grid = dark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.06)';

    return {
        responsive: true,
        maintainAspectRatio: false,
        color: text,
        scales: {
            x: { grid: { display: false }, ticks: { color: text } },
            y: { beginAtZero: true, grid: { color: grid }, ticks: { color: text, precision: 0 } },
        },
        plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, color: text, font: { size: 11 } } } },
        ...extra,
    };
}

function chartComponent(build) {
    return (...args) => ({
        chart: null,
        gone: false,
        async init() {
            const Chart = await loadChart();
            // Removed while Chart.js was loading (a re-render, or navigating away): nothing to draw.
            if (this.gone || !this.$el.isConnected) return;
            this.chart = new Chart(this.$el, build(...args));
        },
        destroy() {
            this.gone = true;
            // Livewire can remove the canvas mid-animation (e.g. opening a payroll run
            // right after the page loads). Destroying at once nulls the context while
            // Chart.js still draws one more frame: "Cannot read properties of null
            // (reading 'save')". So: stop now, destroy on the next frame. A last draw
            // lands harmlessly on the detached canvas.
            const chart = this.chart;
            this.chart = null;
            chart?.stop();
            requestAnimationFrame(() => chart?.destroy());
        },
    });
}

/**
 * The dashboard's attendance line, finance-chart style: one smooth line of the
 * daily "% in" over a soft gradient, light dashed gridlines, no legend, and a
 * hover line + label ("Thu, Oct 15 · 55% in · 17 of 31 expected").
 * Rows: WeeklyTrendQuery (day, date, in, expected, rate; rate null = no data).
 */
function attendanceTrend(rows) {
    const dark = document.documentElement.classList.contains('dark');
    const text = dark ? '#9ca3af' : '#6b7280';
    const grid = dark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.06)';
    const line = COLORS.blue;

    return {
        type: 'line',
        data: {
            labels: rows.map((row) => row.day),
            datasets: [{
                label: '% in',
                data: rows.map((row) => row.rate),
                borderColor: line,
                borderWidth: 2,
                // Smooth, but never past a real value (a plain curve bulged around a Sunday's drop).
                cubicInterpolationMode: 'monotone',
                spanGaps: false,
                pointRadius: rows.map((row, i) => (i === rows.length - 1 && row.rate !== null ? 3.5 : 0)),
                pointBackgroundColor: line,
                pointHoverRadius: 4.5,
                pointHoverBackgroundColor: line,
                pointHoverBorderColor: dark ? '#111827' : '#ffffff',
                pointHoverBorderWidth: 2,
                fill: true,
                // Blue fading to nothing, drawn once the chart knows its height.
                backgroundColor: (context) => {
                    const { ctx, chartArea } = context.chart;
                    if (!chartArea) return 'transparent';
                    const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                    gradient.addColorStop(0, dark ? 'rgba(37,99,235,0.28)' : 'rgba(37,99,235,0.16)');
                    gradient.addColorStop(1, 'rgba(37,99,235,0)');
                    return gradient;
                },
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
                x: { grid: { display: false }, border: { display: false }, ticks: { color: text, maxRotation: 0, autoSkip: true, maxTicksLimit: 8, font: { size: 11 } } },
                y: {
                    min: 0,
                    max: 100,
                    grid: { color: grid, drawTicks: false },
                    border: { dash: [3, 3], display: false },
                    ticks: { color: text, stepSize: 25, padding: 8, callback: (value) => `${value}%`, font: { size: 11 } },
                },
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    displayColors: false,
                    backgroundColor: dark ? '#1f2937' : '#ffffff',
                    titleColor: dark ? '#f9fafb' : '#111827',
                    bodyColor: text,
                    borderColor: dark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.1)',
                    borderWidth: 1,
                    padding: 10,
                    callbacks: {
                        title: (items) => new Date(`${rows[items[0].dataIndex].date}T00:00`).toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' }),
                        label: (item) => {
                            const row = rows[item.dataIndex];
                            return row.rate === null ? 'No data yet' : [`${row.rate}% in`, `${row.in} of ${row.expected} expected`];
                        },
                    },
                },
            },
        },
        // A thin vertical line where the pointer is, like a finance chart.
        plugins: [{
            id: 'hoverLine',
            afterDatasetsDraw(chart) {
                const active = chart.tooltip?.getActiveElements?.() ?? [];
                if (!active.length) return;
                const { ctx, chartArea } = chart;
                const x = active[0].element.x;
                ctx.save();
                ctx.strokeStyle = dark ? 'rgba(255,255,255,0.25)' : 'rgba(0,0,0,0.2)';
                ctx.setLineDash([3, 3]);
                ctx.beginPath();
                ctx.moveTo(x, chartArea.top);
                ctx.lineTo(x, chartArea.bottom);
                ctx.stroke();
                ctx.restore();
            },
        }],
    };
}

export default function registerCharts(Alpine) {
    Alpine.data('attendanceTrendChart', chartComponent(attendanceTrend));

    Alpine.data('todayBreakdownChart', chartComponent((stats) => ({
        type: 'doughnut',
        data: {
            labels: ['On time', 'Late', 'Not in yet', 'On leave'],
            datasets: [{
                data: [stats.on_time, stats.late, stats.absent, stats.on_leave],
                backgroundColor: [COLORS.onTime, COLORS.late, COLORS.absent, COLORS.muted],
                borderWidth: 0,
            }],
        },
        // A thin ring reads cleaner than a thick doughnut next to the line chart.
        options: { ...baseOptions(), scales: {}, cutout: '74%' },
    })));

    Alpine.data('attendanceHistoryChart', chartComponent((days, shiftHours) => ({
        data: {
            labels: days.map((day) => day.chartLabel),
            datasets: [
                {
                    type: 'bar',
                    label: 'Hours worked',
                    data: days.map((day) => day.hours ?? 0),
                    backgroundColor: days.map((day) => STATUS_COLORS[day.status] ?? COLORS.muted),
                    borderRadius: 4,
                    maxBarThickness: 28,
                },
                {
                    type: 'line',
                    label: 'Shift target',
                    data: days.map(() => shiftHours),
                    borderColor: COLORS.target,
                    borderDash: [6, 4],
                    borderWidth: 1.5,
                    pointRadius: 0,
                },
            ],
        },
        options: baseOptions({ plugins: { legend: { display: false } } }),
    })));

    Alpine.data('attendanceYearlyChart', chartComponent((months) => ({
        type: 'bar',
        data: {
            labels: months.map((month) => month.label.slice(0, 3)),
            datasets: [{
                label: 'Hours worked',
                data: months.map((month) => month.totalHours),
                backgroundColor: months.map((month) => (month.punctualityRate >= 90 ? COLORS.onTime : month.punctualityRate >= 75 ? COLORS.late : COLORS.absent)),
                borderRadius: 4,
                maxBarThickness: 36,
            }],
        },
        options: baseOptions({ plugins: { legend: { display: false } } }),
    })));

    Alpine.data('payrollCostChart', chartComponent((trend) => ({
        type: 'bar',
        data: {
            labels: trend.map((row) => row.label),
            datasets: [
                { label: 'Gross', data: trend.map((row) => row.gross), backgroundColor: COLORS.blue, borderRadius: 4 },
                { label: 'Net', data: trend.map((row) => row.net), backgroundColor: COLORS.blueLight, borderRadius: 4 },
            ],
        },
        options: baseOptions(),
    })));

    Alpine.data('deductionsChart', chartComponent((breakdown) => ({
        type: 'doughnut',
        data: {
            labels: ['SSS', 'PhilHealth', 'Pag-IBIG', 'Withholding tax', 'Carried over', 'Late', 'Loans'],
            datasets: [{
                data: [breakdown.sss ?? 0, breakdown.philhealth ?? 0, breakdown.pagibig ?? 0, breakdown.tax ?? 0, breakdown.carried ?? 0, breakdown.late ?? 0, breakdown.loans ?? 0],
                backgroundColor: ['#6366f1', '#0ea5e9', '#10b981', '#f59e0b', '#a855f7', '#ef4444', '#9ca3af'],
                borderWidth: 0,
            }],
        },
        // A thin ring, like the attendance one.
        options: { ...baseOptions(), scales: {}, cutout: '74%' },
    })));
}
