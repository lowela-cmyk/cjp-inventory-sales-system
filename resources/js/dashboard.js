import Chart from 'chart.js/auto';

// Part 10 (October 22, 2026): Dashboard and Analytics charts. The data and every computed figure come from the
// server (DashboardService); this file only draws them. A chart with no data is rendered as "No data yet" by the
// server, so no canvas is created for it.

const RED = '#E8453C';
const GREEN = '#3B9A35';

const number = (value, digits = 2) => Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: digits, maximumFractionDigits: digits });

const formatters = {
    liters: (value) => `${number(value)} L`,
    money: (value) => `${Number(value) < 0 ? '-' : ''}PHP ${number(Math.abs(Number(value || 0)))}`,
    count: (value) => Number(value || 0).toLocaleString(),
};

// Axis numbers are plain, as on the mockup (100, 80, 60...).
const tickFormat = () => (value) => Number(value).toLocaleString();

// y-axis per chart, as the mockup draws it: numbers and gridlines, gridlines only, or nothing.
const yScale = (mode, format) => ({
    beginAtZero: true,
    display: mode !== 'none',
    border: { display: mode === 'ticks' },
    grid: { display: mode !== 'none', color: '#e5e7eb' },
    ticks: { display: mode === 'ticks', callback: tickFormat(format), precision: format === 'count' ? 0 : undefined, font: { size: 10 } },
});

// "Completed / 68.8%" written beside each pie slice (mockup), instead of a legend.
const sliceLabels = {
    id: 'dashSliceLabels',
    afterDatasetsDraw(chart, _args, options) {
        if (!options?.labels?.length) return;
        const { ctx } = chart;
        const arcs = chart.getDatasetMeta(0).data;
        ctx.save();
        ctx.font = '700 10px Arial, Helvetica, sans-serif';
        ctx.fillStyle = '#555';
        arcs.forEach((arc, index) => {
            if (!chart.data.datasets[0].data[index]) return;
            const angle = (arc.startAngle + arc.endAngle) / 2;
            const radius = arc.outerRadius + 14;
            const x = arc.x + Math.cos(angle) * radius;
            const y = arc.y + Math.sin(angle) * radius;
            ctx.textAlign = Math.cos(angle) >= 0 ? 'left' : 'right';
            ctx.fillText(chart.data.labels[index], x, y - 5);
            ctx.fillText(options.labels[index], x, y + 7);
        });
        ctx.restore();
    },
};

// Values on top of the bars (Stock Out vs Receivables in the mockup).
const barValues = {
    id: 'dashBarValues',
    afterDatasetsDraw(chart, _args, options) {
        if (!options?.display) return;
        const { ctx } = chart;
        ctx.save();
        ctx.font = '700 11px Arial, Helvetica, sans-serif';
        ctx.fillStyle = '#111';
        ctx.textAlign = 'center';
        chart.data.datasets.forEach((dataset, datasetIndex) => {
            chart.getDatasetMeta(datasetIndex).data.forEach((bar, index) => {
                ctx.fillText(options.format(dataset.data[index]), bar.x, bar.y - 6);
            });
        });
        ctx.restore();
    },
};

const renderChart = (canvas) => {
    let config;
    try {
        config = JSON.parse(canvas.dataset.chart || '{}');
    } catch {
        canvas.hidden = true;
        return;
    }

    const format = formatters[config.format] || formatters.count;
    const isPie = config.type === 'pie';

    new Chart(canvas, {
        type: config.type || 'bar',
        data: {
            labels: config.labels || [],
            // Wide, square bars as on the mockup.
            // Pie slices meet without white separators (Part 10 mockup); a 0% slice then leaves no line behind.
            datasets: (config.datasets || []).map((dataset) => ({ borderRadius: 0, barPercentage: 0.82, categoryPercentage: 0.9, ...dataset, ...(isPie ? { borderWidth: 0 } : {}) })),
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            layout: { padding: isPie ? 34 : { top: config.showValues ? 18 : 4 } },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (context) => (isPie ? `${context.label}: ${format(context.raw)}` : format(context.raw)),
                    },
                },
                dashBarValues: { display: Boolean(config.showValues), format },
                dashSliceLabels: { labels: config.sliceLabels || [] },
            },
            scales: isPie ? {} : {
                x: { grid: { display: false }, border: { display: config.yAxis !== 'none' }, ticks: { font: { size: 10 } } },
                y: yScale(config.yAxis || 'ticks', config.format),
            },
        },
        plugins: [barValues, sliceLabels],
    });
};

document.querySelectorAll('[data-dashboard-chart]').forEach(renderChart);

// Expected Sales Revenue: Actual (red) and Expected (green), regrouped by the Week / Month / Year toggle without a reload.
document.querySelectorAll('[data-dashboard-revenue]').forEach((root) => {
    let series;
    try {
        series = JSON.parse(root.dataset.series || '{}');
    } catch {
        return;
    }

    const canvas = root.querySelector('canvas');
    const empty = root.querySelector('[data-revenue-empty]');
    const rate = root.querySelector('[data-revenue-increase]');
    const buttons = [...root.querySelectorAll('[data-revenue-period]')];
    let chart = null;

    const show = (period) => {
        const data = series[period];
        if (!data || !canvas) return;

        buttons.forEach((button) => {
            const selected = button.dataset.revenuePeriod === period;
            button.classList.toggle('is-selected', selected);
            button.setAttribute('aria-pressed', selected ? 'true' : 'false');
        });
        if (rate) rate.textContent = data.increaseRateLabel;

        canvas.hidden = data.empty;
        if (empty) empty.hidden = !data.empty;
        if (data.empty) return;

        const chartData = {
            labels: data.labels,
            datasets: [
                { label: 'Actual', data: data.actual, borderColor: RED, backgroundColor: RED, borderWidth: 2, pointRadius: 3, tension: 0, spanGaps: false },
                { label: 'Expected', data: data.expected, borderColor: GREEN, backgroundColor: GREEN, borderWidth: 2, pointRadius: 3, tension: 0 },
            ],
        };

        if (chart) {
            chart.data = chartData;
            chart.update();
            return;
        }

        chart = new Chart(canvas, {
            type: 'line',
            data: chartData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    // "● Actual ● Expected" above the chart (mockup).
                    legend: { position: 'top', labels: { usePointStyle: true, pointStyle: 'circle', boxWidth: 6, boxHeight: 6, font: { size: 10 } } },
                    tooltip: { callbacks: { label: (context) => `${context.dataset.label}: ${formatters.money(context.raw)}` } },
                },
                scales: {
                    x: { grid: { display: false }, ticks: { font: { size: 10 } } },
                    y: yScale('ticks', 'money'),
                },
            },
        });
    };

    buttons.forEach((button) => button.addEventListener('click', () => show(button.dataset.revenuePeriod)));
    show(root.dataset.defaultPeriod || 'month');
});

// Sales Trend "Date": picking a preset replaces a date range that came from a link.
document.querySelectorAll('[data-sales-period]').forEach((select) => {
    select.addEventListener('change', () => {
        select.form?.querySelectorAll('input[name="sales_date_from"], input[name="sales_date_to"]').forEach((input) => input.remove());
    });
});
