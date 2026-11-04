// <dialog> による確認。取消が初期フォーカス、Esc で閉じる
export function confirmDialog(title, body, okLabel = '実行する') {
  const dlg = document.getElementById('confirm-dialog');
  if (!dlg || typeof dlg.showModal !== 'function') {
    return Promise.resolve(window.confirm(`${title}\n${body}`));
  }
  dlg.querySelector('.dialog__title').textContent = title;
  dlg.querySelector('.dialog__body').textContent = body;
  dlg.querySelector('button[value="ok"]').textContent = okLabel;
  dlg.returnValue = '';
  dlg.showModal();
  return new Promise((resolve) => {
    dlg.addEventListener('close', () => resolve(dlg.returnValue === 'ok'), { once: true });
  });
}
