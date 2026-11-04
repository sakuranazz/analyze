// 印刷用画面のボタン（インラインの onclick は CSP で使えないため）
document.querySelector('[data-print]')?.addEventListener('click', () => window.print());
document.querySelector('[data-back]')?.addEventListener('click', (e) => { e.preventDefault(); history.back(); });

// グラフ（分析結果）: 印刷用は白背景・黒系の線
const data = document.getElementById('chart-data');
if (data) {
  import(`./chart-setup.js${new URL(import.meta.url).search}`).then((m) => m.init?.());
}
