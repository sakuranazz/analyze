// 分析結果の推移グラフ（DESIGN.md 4.12）。項目ごとの小さな複数グラフ。系列の色はロットごと、線種でも区別
export async function init() {
  const dataEl = document.getElementById('chart-data');
  const box = document.getElementById('charts');
  if (!dataEl || !box) return;
  await loadChartJs();
  const { series, fields } = JSON.parse(dataEl.textContent);
  const css = getComputedStyle(document.documentElement);
  const color = (i) => css.getPropertyValue(`--chart-${(i % 6) + 1}`).trim();
  const grid = css.getPropertyValue('--border').trim();
  const text = css.getPropertyValue('--text-muted').trim();
  const dashes = [[], [6, 4], [2, 3], [10, 3, 2, 3]];
  const lots = Object.keys(series);

  for (const [key, f] of Object.entries(fields)) {
    if (key === 'room_temp') continue;
    const has = lots.some((l) => series[l].some((r) => r[key] !== null));
    if (!has) continue;
    const card = document.createElement('div');
    card.className = 'card card__body';
    const h = document.createElement('h3');
    h.textContent = f.unit ? `${f.label} (${f.unit})` : f.label;
    const wrap = document.createElement('div');
    wrap.className = 'chart-box';
    const canvas = document.createElement('canvas');
    canvas.setAttribute('role', 'img');
    canvas.setAttribute('aria-label', `${f.label}の推移`);
    wrap.append(canvas);
    card.append(h, wrap);
    box.append(card);
    // eslint-disable-next-line no-undef
    new Chart(canvas, {
      type: 'line',
      data: {
        datasets: lots.slice(0, 6).map((lot, i) => ({
          label: lot,
          data: series[lot].filter((r) => r[key] !== null).map((r) => ({ x: r.d, y: r[key] })),
          borderColor: color(i), backgroundColor: color(i), borderDash: dashes[i % dashes.length],
          pointRadius: 2, pointHoverRadius: 5, tension: 0.2, spanGaps: true,
        })),
      },
      options: {
        responsive: true, maintainAspectRatio: false, animation: false,
        parsing: false,
        scales: {
          x: { type: 'category', labels: [...new Set(lots.flatMap((l) => series[l].map((r) => r.d)))].sort(), ticks: { color: text, font: { size: 11 }, maxRotation: 0, autoSkip: true, callback(v) { return this.getLabelForValue(v).slice(5); } }, grid: { color: grid } },
          y: { ticks: { color: text, font: { size: 11 } }, grid: { color: grid } },
        },
        plugins: {
          legend: { labels: { color: text, boxWidth: 18, font: { size: 11 } } },
          tooltip: { callbacks: { label: (c) => `${c.dataset.label}: ${c.parsed.y}${f.unit}` } },
        },
      },
    });
  }
}

function loadChartJs() {
  if (window.Chart) return Promise.resolve();
  const src = new URL(`./vendor/chart.umd.js${new URL(import.meta.url).search}`, import.meta.url);
  return new Promise((resolve, reject) => {
    const s = document.createElement('script');
    s.src = src.toString();
    s.onload = resolve;
    s.onerror = reject;
    document.head.append(s);
  });
}
