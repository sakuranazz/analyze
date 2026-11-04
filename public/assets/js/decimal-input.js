// 小数の入力欄（<input data-decimal="1">）: 小数第1位まで入力できるようにする
// - 全角数字・全角ピリオド・日本語キーボードの「。」を半角の「.」に直す
// - 数字・小数点・先頭のマイナス以外は入らない。小数点は1つ、小数は data-decimal の桁まで
// - スマホの数字キーボードにはマイナスがないため、data-signed の欄は ± ボタンで符号を切り替える
const toHalf = (s) => s
  .replace(/[０-９]/g, (c) => String.fromCharCode(c.charCodeAt(0) - 0xFEE0))
  .replace(/[．。｡，,]/g, '.')
  .replace(/[－−ー‐―]/g, '-');

export function clean(value, decimals, signed) {
  let s = toHalf(value).replace(/[^\d.-]/g, '');
  const neg = signed && s.startsWith('-');
  s = s.replace(/-/g, '');
  const dot = s.indexOf('.');
  if (dot !== -1) {
    const intPart = s.slice(0, dot) || '0';
    const frac = s.slice(dot + 1).replace(/\./g, '').slice(0, decimals);
    s = decimals > 0 ? `${intPart}.${frac}` : intPart;
  }
  return (neg ? '-' : '') + s;
}

function apply(el) {
  const next = clean(el.value, parseInt(el.dataset.decimal || '1', 10), el.hasAttribute('data-signed'));
  if (next !== el.value) {
    el.value = next;
    try { el.setSelectionRange(next.length, next.length); } catch { /* 対応しない欄は無視 */ }
  }
}

const isDecimal = (el) => el instanceof HTMLInputElement && el.hasAttribute('data-decimal');

export function initDecimalInputs(root = document) {
  // 日本語入力（Mac・iPhone の変換中）に値を書き換えると、入力中の文字が壊れる（「１。５」→「11.1」）。
  // 変換中は触らず、確定したとき（compositionend）と欄を離れたときに直す
  root.addEventListener('input', (e) => {
    if (!isDecimal(e.target) || e.isComposing) return;
    apply(e.target);
  });
  root.addEventListener('compositionend', (e) => {
    if (!isDecimal(e.target)) return;
    // 確定直後の input と順番が前後するブラウザがあるため、次の処理で直す
    setTimeout(() => {
      apply(e.target);
      e.target.dispatchEvent(new Event('input', { bubbles: true }));
    }, 0);
  });
  root.addEventListener('change', (e) => { if (isDecimal(e.target)) apply(e.target); });

  // ± ボタン: 押すたびに符号を切り替える（スマホの数字キーボードにマイナスがないため）
  root.addEventListener('click', (e) => {
    const btn = e.target.closest?.('[data-sign-toggle]');
    if (!btn) return;
    e.preventDefault();
    const el = document.getElementById(btn.dataset.signToggle);
    if (!el) return;
    el.value = el.value.startsWith('-') ? el.value.slice(1) : `-${el.value}`;
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.focus();
  });
}
