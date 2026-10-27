// Charts on the responses page, drawn with ECharts from the [data-chart] specs of
// App\Support\SurveySummary: pie (one choice), bar (several choices), column (ranges, sizes,
// hours), line (long timelines) and stacked (matrix). Colours follow the light/dark theme and the
// admin's accent colour; every chart redraws when either changes.
(function () {
    'use strict';

    // Categorical colours in a fixed order, checked for colour-blind separation (light / dark steps).
    const CATEGORICAL = {
        light: ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'],
        dark: ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181', '#008300', '#9085e9', '#e66767'],
    };
    const charts = [];

    function tokens() {
        const style = getComputedStyle(document.documentElement);
        const read = (name, fallback) => style.getPropertyValue(name).trim() || fallback;
        const dark = document.documentElement.dataset.theme === 'dark';

        return {
            dark,
            brand: read('--brand', '#087e78'),
            text: read('--text', '#132e2c'),
            text2: read('--text-2', '#46625e'),
            text3: read('--text-3', '#7a8f8b'),
            border: read('--border', '#dbe6e3'),
            surface: read('--surface', '#ffffff'),
            categorical: CATEGORICAL[dark ? 'dark' : 'light'],
        };
    }

    const number = value => Number(value).toLocaleString('id-ID');

    function tooltip(theme, trigger) {
        return {
            trigger,
            confine: true,
            backgroundColor: theme.surface,
            borderColor: theme.border,
            borderWidth: 1,
            padding: [8, 12],
            textStyle: { color: theme.text, fontFamily: 'inherit', fontSize: 13 },
            extraCssText: 'border-radius:10px;box-shadow:0 8px 24px rgb(0 0 0 / .14)',
            axisPointer: trigger === 'axis' ? { type: 'line', lineStyle: { color: theme.text3, type: 'dashed' } } : undefined,
        };
    }

    function axisStyle(theme) {
        return {
            axisLine: { lineStyle: { color: theme.border } },
            axisTick: { show: false },
            axisLabel: { color: theme.text3, fontSize: 12, hideOverlap: true },
            splitLine: { lineStyle: { color: theme.border, type: 'dashed', opacity: 0.7 } },
        };
    }

    function pieOption(spec, theme) {
        const data = spec.categories.map((name, index) => ({ name, value: spec.series[0].data[index] }));
        const total = data.reduce((sum, item) => sum + item.value, 0);

        return {
            color: theme.categorical,
            tooltip: { ...tooltip(theme, 'item'), formatter: item => `<b>${item.marker} ${item.name}</b><br>${number(item.value)} ${spec.unit} · ${item.percent}%` },
            legend: { bottom: 0, icon: 'circle', itemWidth: 10, itemHeight: 10, textStyle: { color: theme.text2, fontSize: 12 }, type: 'scroll' },
            series: [{
                type: 'pie',
                radius: ['46%', '72%'],
                center: ['50%', '44%'],
                padAngle: 1.5,
                percentPrecision: 0,
                itemStyle: { borderRadius: 6, borderColor: theme.surface, borderWidth: 2 },
                label: { color: theme.text2, fontSize: 12, formatter: item => `${item.percent}%` },
                labelLine: { lineStyle: { color: theme.border } },
                emphasis: { scaleSize: 6, label: { fontWeight: 700, color: theme.text } },
                data,
                animationType: 'scale',
                animationEasing: 'cubicOut',
            }],
            graphic: [{ type: 'text', left: 'center', top: '38%', style: { text: number(total), fill: theme.text, fontSize: 22, fontWeight: 800, align: 'center' } },
                { type: 'text', left: 'center', top: '49%', style: { text: spec.unit, fill: theme.text3, fontSize: 12, align: 'center' } }],
        };
    }

    // Bars (horizontal) and columns (vertical): one series in the accent colour, values on short charts.
    function barOption(spec, theme, horizontal) {
        const axis = axisStyle(theme);
        const values = spec.series[0].data;
        const showLabels = spec.categories.length <= 10;
        const category = { type: 'category', data: spec.categories, ...axis, axisLabel: { ...axis.axisLabel, color: theme.text2, width: horizontal ? 140 : undefined, overflow: 'truncate', interval: horizontal ? 0 : 'auto' }, inverse: horizontal };
        const value = { type: 'value', minInterval: 1, ...axis, axisLine: { show: false } };

        return {
            grid: { left: 8, right: horizontal ? 40 : 12, top: 24, bottom: 4, containLabel: true },
            tooltip: { ...tooltip(theme, 'axis'), axisPointer: { type: 'shadow', shadowStyle: { color: theme.dark ? 'rgba(255,255,255,.05)' : 'rgba(0,0,0,.04)' } }, formatter: items => `<b>${items[0].name}</b><br>${number(items[0].value)} ${spec.unit}` },
            xAxis: horizontal ? value : category,
            yAxis: horizontal ? category : value,
            series: [{
                type: 'bar',
                data: values,
                barMaxWidth: horizontal ? 22 : 44,
                itemStyle: {
                    borderRadius: horizontal ? [0, 4, 4, 0] : [4, 4, 0, 0],
                    color: new echarts.graphic.LinearGradient(0, 0, horizontal ? 1 : 0, horizontal ? 0 : 1, horizontal
                        ? [{ offset: 0, color: `${theme.brand}b3` }, { offset: 1, color: theme.brand }]
                        : [{ offset: 0, color: theme.brand }, { offset: 1, color: `${theme.brand}b3` }]),
                },
                emphasis: { itemStyle: { color: theme.brand, shadowBlur: 10, shadowColor: `${theme.brand}66` } },
                label: { show: showLabels, position: horizontal ? 'right' : 'top', color: theme.text2, fontSize: 12, fontWeight: 600, formatter: item => (item.value ? number(item.value) : '') },
                animationDelay: index => index * 40,
            }],
        };
    }

    // A smooth area line for long timelines, with a crosshair tooltip.
    function lineOption(spec, theme) {
        const axis = axisStyle(theme);

        return {
            grid: { left: 8, right: 16, top: 24, bottom: 4, containLabel: true },
            tooltip: { ...tooltip(theme, 'axis'), formatter: items => `<b>${items[0].name}</b><br>${number(items[0].value)} ${spec.unit}` },
            xAxis: { type: 'category', data: spec.categories, boundaryGap: false, ...axis },
            yAxis: { type: 'value', minInterval: 1, ...axis, axisLine: { show: false } },
            series: [{
                type: 'line',
                data: spec.series[0].data,
                smooth: 0.35,
                symbol: 'circle',
                symbolSize: 8,
                showSymbol: spec.categories.length <= 40,
                lineStyle: { width: 2, color: theme.brand },
                itemStyle: { color: theme.brand, borderColor: theme.surface, borderWidth: 2 },
                areaStyle: { color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [{ offset: 0, color: `${theme.brand}59` }, { offset: 1, color: `${theme.brand}05` }]) },
                emphasis: { focus: 'series' },
            }],
        };
    }

    // A matrix: each row's answers split by column, stacked horizontally.
    function stackedOption(spec, theme) {
        const axis = axisStyle(theme);
        const last = spec.series.length - 1;

        return {
            color: theme.categorical,
            grid: { left: 8, right: 16, top: 36, bottom: 4, containLabel: true },
            legend: { top: 0, icon: 'circle', itemWidth: 10, itemHeight: 10, textStyle: { color: theme.text2, fontSize: 12 }, type: 'scroll' },
            tooltip: { ...tooltip(theme, 'axis'), axisPointer: { type: 'shadow' } },
            xAxis: { type: 'value', minInterval: 1, ...axis, axisLine: { show: false } },
            yAxis: { type: 'category', data: spec.categories, inverse: true, ...axis, axisLabel: { ...axis.axisLabel, color: theme.text2, width: 160, overflow: 'truncate' } },
            series: spec.series.map((series, index) => ({
                name: series.name,
                type: 'bar',
                stack: 'total',
                data: series.data,
                barMaxWidth: 24,
                itemStyle: { borderColor: theme.surface, borderWidth: 1, borderRadius: index === last ? [0, 4, 4, 0] : 0 },
                emphasis: { focus: 'series' },
            })),
        };
    }

    function optionFor(spec, theme) {
        switch (spec.type) {
            case 'pie': return pieOption(spec, theme);
            case 'bar': return barOption(spec, theme, true);
            case 'line': return lineOption(spec, theme);
            case 'stacked': return stackedOption(spec, theme);
            default: return barOption(spec, theme, false);
        }
    }

    function heightFor(spec) {
        if (spec.type === 'bar' || spec.type === 'stacked') return Math.max(140, spec.categories.length * 38 + (spec.type === 'stacked' ? 56 : 28));
        return spec.type === 'pie' ? 280 : 240;
    }

    function drawAll() {
        const theme = tokens();
        charts.forEach(entry => {
            entry.chart.setOption({ ...optionFor(entry.spec, theme), textStyle: { fontFamily: getComputedStyle(document.body).fontFamily } }, true);
        });
    }

    function init() {
        if (!window.echarts) return;
        document.querySelectorAll('[data-chart]').forEach(element => {
            let spec;
            try {
                spec = JSON.parse(element.dataset.chart);
            } catch {
                return;
            }
            element.style.height = `${heightFor(spec)}px`;
            charts.push({ spec, chart: echarts.init(element, null, { renderer: 'svg' }) });
        });
        drawAll();

        const resize = new ResizeObserver(() => charts.forEach(entry => entry.chart.resize()));
        charts.forEach(entry => resize.observe(entry.chart.getDom()));

        // The theme switch and the accent picker change the page's colours.
        let timer = null;
        const redraw = () => {
            clearTimeout(timer);
            timer = setTimeout(drawAll, 60);
        };
        new MutationObserver(redraw).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme', 'data-accent'] });
        document.addEventListener('load', event => {
            if (event.target.matches?.('[data-accent-css]')) redraw();
        }, true);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
