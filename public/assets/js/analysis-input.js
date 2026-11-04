// 分析結果入力: 前回値と大きく違う（±50%超）ときは欄を注意色にするだけ（保存は止めない。DESIGN.md 4.2）
export async function init() {
  const { clean } = await import(`./decimal-input.js${new URL(import.meta.url).search}`);
  const form = document.querySelector('.analysis-form');
  if (!form) return;
  const prev = JSON.parse(form.dataset.prev || '{}');
  form.querySelectorAll('[data-field]').forEach((input) => {
    const key = input.dataset.field;
    const box = input.closest('.input-unit');
    const note = form.querySelector(`[data-prev-note="${key}"]`);
    const base = note?.textContent ?? '';
    input.addEventListener('input', () => {
      const v = parseFloat(clean(input.value, 1, true));   // 全角・「。」も半角に直してから比べる
      const p = prev[key];
      const off = p !== undefined && Math.abs(p) >= 0.1 && !Number.isNaN(v) && Math.abs(v - p) > Math.abs(p) * 0.5;
      box.classList.toggle('is-warn', off);
      if (note) {
        note.classList.toggle('is-warn', off);
        note.textContent = off ? `${base} · 差が大きい` : base;
      }
    });
  });
  // ロットを変えたら、そのロットの前回値で開き直す
  form.querySelector('[data-lot-select]')?.addEventListener('change', (e) => {
    const url = new URL(location.href);
    url.searchParams.set('lot_id', e.target.value);
    location.href = url.toString();
  });
}
