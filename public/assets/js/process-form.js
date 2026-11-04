// 工程入力: 種別に応じて欄を出し分け、行の追加・削除、容器単位の単価の換算表示（DESIGN.md 4.4）
const toNum = (s) => {
  const v = String(s ?? '').replace(/[０-９．]/g, (c) => String.fromCharCode(c.charCodeAt(0) - 0xFEE0)).replace(/[,，\s¥￥円]/g, '');
  return /^\d+(\.\d+)?$/.test(v) ? parseFloat(v) : NaN;
};

export function init() {
  const form = document.querySelector('form[data-guard]');
  if (!form || !form.querySelector('[data-type]')) return;
  const help = JSON.parse(document.getElementById('type-help')?.textContent || '{}');
  const tbody = form.querySelector('[data-lines] tbody');

  const sync = () => {
    const type = form.querySelector('[data-type]:checked')?.value;
    form.querySelectorAll('[data-only]').forEach((el) => {
      el.hidden = !el.dataset.only.split(' ').includes(type);
    });
    const h = form.querySelector('[data-type-help]');
    if (h) h.textContent = help[type] ?? '';
    // 仕入・仕込みは向きを固定の既定にする（空の行だけ）
    form.querySelectorAll('[data-line]').forEach((tr) => {
      const mat = tr.querySelector('[data-material]').value;
      if (mat) return;
      const dir = tr.querySelector('[data-dir]');
      dir.value = type === 'purchase' ? '+' : '-';
    });
  };
  form.querySelectorAll('[data-type]').forEach((r) => r.addEventListener('change', sync));
  sync();

  // 行の追加（最後の行を複製して番号を振り直す）
  let next = form.querySelectorAll('[data-line]').length;
  form.querySelector('[data-add-line]')?.addEventListener('click', () => {
    const rows = form.querySelectorAll('[data-line]');
    const clone = rows[rows.length - 1].cloneNode(true);
    clone.querySelectorAll('input').forEach((i) => { i.value = ''; });
    clone.querySelectorAll('select').forEach((s) => { s.selectedIndex = s.matches('[data-dir]') ? s.selectedIndex : 0; });
    clone.querySelectorAll('[name]').forEach((el) => { el.name = el.name.replace(/lines\[\d+\]/, `lines[${next}]`); });
    clone.querySelector('[data-cout]').textContent = '';
    next++;
    tbody.append(clone);
    clone.querySelector('select')?.focus();
  });
  tbody.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-remove]');
    if (!btn) return;
    const tr = btn.closest('tr');
    if (form.querySelectorAll('[data-line]').length > 1) tr.remove();
    else tr.querySelectorAll('input').forEach((i) => { i.value = ''; });
  });

  // 容器単位: 「= ¥45 / L」のように換算結果を出す
  tbody.addEventListener('input', (e) => {
    const tr = e.target.closest('[data-line]');
    if (!tr) return;
    const p = toNum(tr.querySelector('[data-cprice]')?.value);
    const q = toNum(tr.querySelector('[data-cqty]')?.value);
    const unit = tr.querySelector('[data-material]').selectedOptions[0]?.dataset.unit ?? '単位';
    const out = tr.querySelector('[data-cout]');
    if (out) out.textContent = p > 0 && q > 0 ? `= ¥${(p / q).toLocaleString('ja-JP', { maximumFractionDigits: 4 })} / ${unit}` : '';
  });
}
