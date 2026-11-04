// 成功だけをトーストで2.5秒出す。エラーは通知帯で残す（DESIGN.md 5章）
let timer;
export function toast(message) {
  const el = document.querySelector('.toast');
  if (!el) return;
  el.textContent = message;
  el.hidden = false;
  clearTimeout(timer);
  timer = setTimeout(() => { el.hidden = true; }, 2500);
}
