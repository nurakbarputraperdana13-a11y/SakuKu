(() => {
  'use strict';

  const root = document.getElementById('charts-root');
  if (!root) return;

  const categoryCanvas = document.getElementById('chart-category');
  const trendCanvas = document.getElementById('chart-trend');
  if (!categoryCanvas || !trendCanvas) return;

  const endpoint = (root.dataset.endpoint || '').trim();
  const initialMonth = /^\d{4}-\d{2}$/.test(root.dataset.month || '') ? root.dataset.month : '';
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const numberFormat = new Intl.NumberFormat('id-ID');
  const charts = { category: null, trend: null };
  let payload = null;
  let selectedMonth = initialMonth;

  const palettes = {
    light: ['#1e40af', '#047857', '#b45309', '#dc2626', '#7c3aed', '#0891b2'],
    dark: ['#60a5fa', '#34d399', '#fbbf24', '#f87171', '#a78bfa', '#22d3ee'],
  };

  function rupiah(value) {
    const amount = Math.round(Number(value) || 0);
    return 'Rp' + numberFormat.format(amount);
  }

  function themeName() {
    return document.documentElement.dataset.theme === 'light' ? 'light' : 'dark';
  }

  function cssVar(name, fallback) {
    const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    return value || fallback;
  }

  function palette() {
    const theme = themeName();
    const light = theme === 'light';
    return {
      theme,
      text: cssVar('--color-foreground', light ? '#0f172a' : '#f8fafc'),
      muted: cssVar('--color-muted-foreground', light ? '#5b6b82' : '#a7b4c8'),
      grid: cssVar('--color-border', light ? 'rgba(15, 23, 42, 0.1)' : 'rgba(255, 255, 255, 0.1)'),
      surface: cssVar('--color-surface-solid', light ? '#ffffff' : '#192134'),
      primary: cssVar('--color-primary', light ? '#1e40af' : '#60a5fa'),
      accent: cssVar('--color-accent', light ? '#047857' : '#34d399'),
      danger: cssVar('--color-danger', light ? '#dc2626' : '#f87171'),
      doughnut: palettes[theme],
    };
  }

  function showMessage(message) {
    for (const canvas of [categoryCanvas, trendCanvas]) {
      const box = canvas.parentElement;
      canvas.hidden = true;
      if (!box) continue;
      let notice = box.querySelector('[data-chart-message]');
      if (!notice) {
        notice = document.createElement('div');
        notice.className = 'empty-state';
        notice.dataset.chartMessage = 'true';
        box.appendChild(notice);
      }
      notice.textContent = message;
    }
  }

  function clearMessages() {
    for (const canvas of [categoryCanvas, trendCanvas]) {
      canvas.hidden = false;
      const box = canvas.parentElement;
      if (!box) continue;
      box.querySelectorAll('[data-chart-message]').forEach((notice) => notice.remove());
    }
  }

  function destroyCharts() {
    for (const key of ['category', 'trend']) {
      if (charts[key]) {
        charts[key].destroy();
        charts[key] = null;
      }
    }
  }

  function fetchUrl() {
    const url = new URL(endpoint, window.location.href);
    if (selectedMonth) url.searchParams.set('month', selectedMonth);
    return url.toString();
  }

  function sanitizeRows(rows) {
    if (!Array.isArray(rows)) return [];
    return rows.filter((row) => row && typeof row === 'object');
  }

  function daysInMonth(month) {
    const value = /^\d{4}-\d{2}$/.test(month || '') ? month : new Date().toISOString().slice(0, 7);
    const [year, monthNumber] = value.split('-').map(Number);
    return new Date(year, monthNumber, 0).getDate();
  }

  function dayLabel(day) {
    const date = new Date(`${day}T00:00:00`);
    if (Number.isNaN(date.getTime())) return day;
    return new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short' }).format(date);
  }

  function fullDayLabel(day) {
    const date = new Date(`${day}T00:00:00`);
    if (Number.isNaN(date.getTime())) return day;
    return new Intl.DateTimeFormat('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(date);
  }

  function animationOptions() {
    if (reducedMotion.matches) return false;
    return { duration: 450, easing: 'easeOutQuart' };
  }

  function renderCategory(items, colors) {
    const rows = sanitizeRows(items);
    const hasData = rows.length > 0;
    const labels = hasData ? rows.map((row) => String(row.label || 'Tanpa kategori')) : ['Belum ada data'];
    const values = hasData ? rows.map((row) => Math.max(0, Number(row.total) || 0)) : [1];
    const background = hasData ? colors.doughnut : ['#94a3b8'];
    const total = values.reduce((sum, value) => sum + value, 0);

    charts.category = new Chart(categoryCanvas, {
      type: 'doughnut',
      data: {
        labels,
        datasets: [{
          data: values,
          backgroundColor: background,
          borderColor: colors.surface,
          borderWidth: 3,
          hoverOffset: reducedMotion.matches ? 0 : 6,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: animationOptions(),
        cutout: '62%',
        plugins: {
          legend: {
            position: 'bottom',
            labels: {
              color: colors.text,
              usePointStyle: true,
              boxWidth: 8,
              padding: 14,
              font: { family: "'Quicksand', 'Segoe UI', sans-serif", size: 11 },
            },
          },
          tooltip: {
            enabled: hasData,
            callbacks: {
              label(context) {
                const percent = total > 0 ? Math.round((context.parsed / total) * 100) : 0;
                return ` ${context.label}: ${rupiah(context.parsed)} (${percent}%)`;
              },
            },
          },
        },
      },
    });
  }

  function renderTrend(items, colors, month) {
    const byDay = new Map();
    for (const row of sanitizeRows(items)) {
      const day = String(row.day || '');
      if (/^\d{4}-\d{2}-\d{2}$/.test(day)) byDay.set(day, row);
    }

    const reportMonth = /^\d{4}-\d{2}$/.test(month || '') ? month : new Date().toISOString().slice(0, 7);
    const days = [];
    const count = daysInMonth(reportMonth);
    for (let index = 1; index <= count; index += 1) {
      days.push(`${reportMonth}-${String(index).padStart(2, '0')}`);
    }

    const income = days.map((day) => Math.max(0, Number((byDay.get(day) || {}).income) || 0));
    const expense = days.map((day) => Math.max(0, Number((byDay.get(day) || {}).expense) || 0));

    charts.trend = new Chart(trendCanvas, {
      type: 'bar',
      data: {
        labels: days.map(dayLabel),
        datasets: [
          {
            type: 'bar',
            label: 'Pengeluaran',
            data: expense,
            backgroundColor: colors.danger,
            borderRadius: 4,
            maxBarThickness: 18,
            order: 2,
          },
          {
            type: 'line',
            label: 'Pemasukan',
            data: income,
            borderColor: colors.primary,
            backgroundColor: colors.primary,
            pointBackgroundColor: colors.surface,
            pointBorderColor: colors.primary,
            pointBorderWidth: 2,
            pointRadius: reducedMotion.matches ? 0 : 3,
            borderWidth: 2,
            tension: 0.35,
            fill: false,
            order: 1,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: animationOptions(),
        interaction: { mode: 'index', intersect: false },
        scales: {
          x: {
            grid: { display: false },
            ticks: { color: colors.muted, maxRotation: 0, maxTicksLimit: 10 },
          },
          y: {
            beginAtZero: true,
            grid: { color: colors.grid },
            ticks: {
              color: colors.muted,
              maxTicksLimit: 6,
              callback(value) {
                return rupiah(value);
              },
            },
          },
        },
        plugins: {
          legend: {
            position: 'top',
            align: 'end',
            labels: {
              color: colors.text,
              usePointStyle: true,
              boxWidth: 8,
              padding: 14,
              font: { family: "'Quicksand', 'Segoe UI', sans-serif", size: 11 },
            },
          },
          tooltip: {
            callbacks: {
              title(items) {
                if (!items || !items.length) return '';
                return fullDayLabel(days[items[0].dataIndex] || '');
              },
              label(context) {
                return ` ${context.dataset.label}: ${rupiah(context.parsed.y)}`;
              },
            },
          },
        },
      },
    });
  }

  function renderAll() {
    if (typeof window.Chart === 'undefined') {
      destroyCharts();
      showMessage('Chart.js tidak tersedia. Grafik tidak dapat ditampilkan.');
      return;
    }
    if (!payload) return;

    const colors = palette();
    Chart.defaults.font.family = "'Quicksand', 'Segoe UI', sans-serif";
    Chart.defaults.color = colors.muted;

    destroyCharts();
    clearMessages();
    renderCategory(payload.category, colors);
    renderTrend(payload.trend, colors, payload.month || selectedMonth);
  }

  async function loadCharts() {
    if (!endpoint) {
      showMessage('Endpoint grafik tidak tersedia.');
      return;
    }
    if (typeof window.Chart === 'undefined') {
      showMessage('Chart.js tidak tersedia. Grafik tidak dapat ditampilkan.');
      return;
    }

    showMessage('Memuat grafik...');
    try {
      const response = await fetch(fetchUrl(), {
        method: 'GET',
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
      });

      let data;
      try {
        data = await response.json();
      } catch (error) {
        throw new Error('Balasan grafik bukan JSON yang valid.');
      }

      if (!response.ok || !data || typeof data !== 'object') {
        const message = data && typeof data.error === 'string' ? data.error : `Gagal memuat grafik (${response.status}).`;
        throw new Error(message);
      }

      selectedMonth = /^\d{4}-\d{2}$/.test(data.month || '') ? data.month : selectedMonth;
      payload = {
        month: selectedMonth,
        category: sanitizeRows(data.category),
        trend: sanitizeRows(data.trend),
      };
      renderAll();
    } catch (error) {
      payload = null;
      destroyCharts();
      showMessage(error && error.message ? error.message : 'Gagal memuat grafik.');
    }
  }

  window.addEventListener('sakuku:themechange', () => {
    if (payload) renderAll();
  });

  if (typeof reducedMotion.addEventListener === 'function') {
    reducedMotion.addEventListener('change', () => {
      if (payload) renderAll();
    });
  }

  loadCharts();
})();
