/* Sixpence charts — thin wrapper over Chart.js that follows the design tokens.
 *
 * Markup contract:
 *   <div class="chart" data-chart="chart-id"><canvas role="img" aria-label="…"></canvas></div>
 *   <script type="application/json" id="chart-id">{ type, labels, series[], currency }</script>
 *
 * series[]: { label, data[], tone: 'in' | 'out' | 'muted', fill?: bool, dashed?: bool }
 */
(() => {
  'use strict';

  const instances = new Map();
  const token = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
  const toneColor = (tone) => token({ in: '--series-in', out: '--series-out', muted: '--series-muted' }[tone] || '--series-in');

  function alpha(hex, a) {
    const n = parseInt(hex.replace('#', ''), 16);
    return `rgba(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}, ${a})`;
  }

  function shortMoney(value, symbol) {
    const abs = Math.abs(value);
    let out;
    if (abs >= 1e6) out = `${+(abs / 1e6).toFixed(1)}M`;
    else if (abs >= 1e4) out = `${Math.round(abs / 1e3)}k`;
    else if (abs >= 1e3) out = `${+(abs / 1e3).toFixed(1)}k`;
    else out = `${Math.round(abs)}`;
    return `${value < 0 ? '−' : ''}${symbol}${out}`;
  }

  function fullMoney(value, symbol) {
    const formatted = Math.abs(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return `${value < 0 ? '−' : ''}${symbol}${formatted}`;
  }

  function config(spec) {
    const ink2 = token('--ink-2');
    const axis = token('--chart-axis');
    const grid = token('--chart-grid');
    const surface = token('--surface');
    const symbol = spec.currency || '$';
    const isBar = spec.type === 'bar';

    const datasets = spec.series.map((s) => {
      const color = toneColor(s.tone);
      const base = { label: s.label, data: s.data };
      if (isBar) {
        return {
          ...base,
          backgroundColor: color,
          hoverBackgroundColor: color,
          borderRadius: { topLeft: 4, topRight: 4 },
          borderSkipped: 'bottom',
          maxBarThickness: 24,
          categoryPercentage: 0.7,
          barPercentage: 0.86,
        };
      }
      return {
        ...base,
        borderColor: color,
        backgroundColor: s.fill ? alpha(color, 0.1) : 'transparent',
        fill: s.fill ? (spec.fillTo === 'zero' ? 'origin' : 'start') : false,
        borderWidth: 2,
        borderDash: s.dashed ? [5, 4] : [],
        pointRadius: 0,
        pointHoverRadius: 5,
        pointHoverBorderWidth: 2,
        pointHoverBorderColor: surface,
        pointHoverBackgroundColor: color,
        tension: 0,
        stepped: spec.stepped ? 'after' : false,
        spanGaps: false,
        clip: 6,
      };
    });

    return {
      type: isBar ? 'bar' : 'line',
      data: { labels: spec.labels, datasets },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: { duration: 500, easing: 'easeOutQuart' },
        interaction: { mode: 'index', intersect: false },
        layout: { padding: { top: 6, right: 6 } },
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: token('--ink'),
            titleColor: token('--ink-inverse'),
            bodyColor: token('--ink-inverse'),
            titleFont: { family: 'Geist', weight: '500', size: 12 },
            bodyFont: { family: 'Geist', size: 13 },
            padding: 10,
            cornerRadius: 8,
            boxWidth: 8,
            boxHeight: 8,
            boxPadding: 4,
            usePointStyle: true,
            callbacks: {
              label: (ctx) => ` ${ctx.dataset.label}: ${ctx.parsed.y === null ? '—' : fullMoney(ctx.parsed.y, symbol)}`,
              labelPointStyle: () => ({ pointStyle: 'rectRounded', rotation: 0 }),
            },
          },
        },
        scales: {
          x: {
            grid: { display: false },
            border: { color: grid },
            ticks: { color: axis, font: { family: 'Geist', size: 11.5 }, maxRotation: 0, autoSkip: true, autoSkipPadding: 16 },
          },
          y: {
            beginAtZero: true,
            grid: { color: grid, lineWidth: 1, drawTicks: false },
            border: { display: false },
            ticks: { color: axis, font: { family: 'Geist', size: 11.5 }, padding: 8, maxTicksLimit: 5, callback: (v) => shortMoney(v, symbol) },
          },
        },
        color: ink2,
      },
    };
  }

  function render(container) {
    const id = container.dataset.chart;
    const source = document.getElementById(id);
    const canvas = container.querySelector('canvas');
    if (!source || !canvas || !window.Chart) return;
    const spec = JSON.parse(source.textContent);
    instances.get(id)?.destroy();
    instances.set(id, new window.Chart(canvas, config(spec)));
  }

  function renderAll() {
    document.querySelectorAll('[data-chart]').forEach(render);
  }

  document.addEventListener('DOMContentLoaded', renderAll);
  document.addEventListener('sixpence:themechange', () => requestAnimationFrame(renderAll));

  window.sixpenceCharts = { renderAll };
})();
