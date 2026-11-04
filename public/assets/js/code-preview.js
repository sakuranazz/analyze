// 作番の画面で、生成されるコードをその場でプレビュー（CLAUDE.md 6.8）。登録時はサーバーの CodeGenerator が同じ規則で作る
export function init() {
  document.querySelectorAll('form[data-code-preview]').forEach((form) => {
    const kind = form.dataset.codePreview;
    const codes = JSON.parse(form.dataset.codes);
    const out = form.querySelector('[data-preview]');
    const val = (p) => form.querySelector(`[data-part="${p}"]`)?.value ?? '';
    const update = () => {
      if (kind === 'sku') {
        const liq = codes.liquor[val('liquor')] ?? '';
        const ing = codes.ingredient[val('ingredient')] ?? '';
        const ml = codes.capacity[val('capacity')] ?? '';
        out.textContent = liq && ml ? `${liq}${ing ? `-${ing}` : ''}_${ml}` : '—';
      } else {
        const liq = codes.liquor[val('liquor')] ?? '';
        const tank = codes.tank[val('tank')] ?? '';
        const ym = val('yymm');
        const seq = val('seq');
        const ok = liq && /^\d{4}$/.test(ym);
        out.textContent = ok ? `${liq}${tank ? `_${tank}` : ''}-${ym}_${seq ? String(seq).padStart(2, '0') : '（次の番号）'}` : '—';
      }
    };
    form.addEventListener('input', update);
    form.addEventListener('change', update);
    update();
  });
}
