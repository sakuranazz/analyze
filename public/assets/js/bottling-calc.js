// 詰口の計算パネル（DESIGN.md 4.3）。最終的な検証はサーバー（BottlingPlanner）が行う。ここは見やすくするためだけ
const num = (s) => {
  const v = String(s ?? '').replace(/[０-９]/g, (c) => String.fromCharCode(c.charCodeAt(0) - 0xFEE0)).replace(/[,，\s]/g, '');
  return /^\d+$/.test(v) ? parseInt(v, 10) : 0;
};
const fmt = (n) => n.toLocaleString('ja-JP');
const liters = (ml) => (ml / 1000).toFixed(2);

export function init() {
  // ロットを変えたら、そのロットの残りで開き直す
  document.querySelector('[data-lot-reload]')?.addEventListener('change', (e) => {
    const url = new URL(location.href);
    url.searchParams.set('lot_id', e.target.value);
    location.href = url.toString();
  });
  // 出庫: 販売のときだけ販売先
  const channel = document.querySelector('[data-channel]');
  const syncKind = () => {
    const k = document.querySelector('[data-kind]:checked')?.value;
    if (channel) channel.hidden = k !== 'sale';
  };
  document.querySelectorAll('[data-kind]').forEach((r) => r.addEventListener('change', syncKind));
  syncKind();

  const form = document.getElementById('bottling-form');
  const panel = document.querySelector('[data-calc-panel]');
  if (!form || !panel || !form.querySelector('[data-session-ml]')) return;
  const cfg = JSON.parse(form.dataset.calc);
  const out = (k) => panel.querySelector(`[data-out="${k}"]`);
  const submit = form.querySelector('[data-submit]');
  const reason = form.querySelector('[data-submit-reason]');

  const update = () => {
    const session = num(form.querySelector('[data-session-ml]').value);
    let bottles = 0;
    let count = 0;
    const cons = new Map();
    for (const c of cfg.caps) {
      const n = num(form.querySelector(`[data-cap="${c.id}"]`)?.value);
      if (!n) continue;
      bottles += c.ml * n;
      count += n;
      const mat = cfg.bottleByCap[c.id];
      const key = mat ? cfg.names[mat] : `${c.ml}ml の瓶（マスタ未登録・減りません）`;
      cons.set(key, (cons.get(key) ?? 0) + n);
    }
    const sku = form.querySelector('[data-sku]').value;
    for (const l of cfg.labelsBySku[sku] ?? []) {
      cons.set(l.name, (cons.get(l.name) ?? 0) + count * (l.qty_per_bottle_milli / 1000));
    }
    const sample = Math.max(0, session - bottles);
    out('bottles').textContent = fmt(bottles);
    out('session').textContent = fmt(session);
    out('sample').textContent = fmt(sample);
    if (cfg.lotTotal !== null) out('after').textContent = liters(cfg.lotTotal - cfg.otherMl - session);

    let msg = '';
    let error = false;
    if (count === 0) { msg = '容量ごとの本数を入れてください'; error = true; }
    else if (bottles > session) { msg = `瓶の合計が今回の液量を ${fmt(bottles - session)} ml 超えています`; error = true; }
    else if (cfg.lotTotal !== null && cfg.otherMl + session > cfg.lotTotal) msg = '⚠ ロットの全液量を超えます（登録はできます）';
    panel.classList.toggle('is-error', error && count > 0);
    out('message').textContent = msg;
    submit.disabled = error;
    reason.textContent = error ? msg : '';

    const ul = out('consumption');
    ul.replaceChildren(...[...cons].map(([name, q]) => {
      const li = document.createElement('li');
      li.textContent = `${name} −${fmt(q)}`;
      return li;
    }));
    if (!cons.size) {
      const li = document.createElement('li');
      li.textContent = 'なし';
      ul.append(li);
    }
  };
  form.addEventListener('input', update);
  form.addEventListener('change', update);
  update();
}
