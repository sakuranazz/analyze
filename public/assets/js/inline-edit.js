// インライン編集（DESIGN.md 4.6、IMPLEMENTATION.md 8.2）
// - 行の「編集」: 同じURLに X-Fragment: 1 を付けて GET → 入力欄の行（断片）に差し替え
// - 保存: POST（X-Fragment: 1）→ 200 は更新後の行、422 はエラー入りの入力行
// - Enter で保存、Esc で取消。JS がない場合は通常のリンク・フォーム送信で同じ動きをする
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

function rowFromHtml(html) {
  const t = document.createElement('template');
  t.innerHTML = html.trim();
  return t.content;
}

async function fetchFragment(url, options = {}) {
  const res = await fetch(url, {
    ...options,
    headers: { 'X-Fragment': '1', 'X-CSRF-Token': csrf(), ...(options.headers ?? {}) },
    credentials: 'same-origin',
  });
  return { status: res.status, html: await res.text() };
}

function replaceRow(row, html) {
  const frag = rowFromHtml(html);
  const first = frag.firstElementChild;
  // 行の下のエラー行など、前回の付属行を消す
  while (row.nextElementSibling?.dataset.rowExtra === row.dataset.rowId) row.nextElementSibling.remove();
  row.replaceWith(frag);
  return first;
}

export function initInlineEdit() {
  const originals = new Map();

  document.addEventListener('click', async (e) => {
    const editLink = e.target.closest('a[data-edit]');
    if (editLink) {
      e.preventDefault();
      const row = editLink.closest('tr');
      originals.set(row.dataset.rowId, row.outerHTML);
      const { status, html } = await fetchFragment(editLink.href);
      if (status === 200) {
        const nr = replaceRow(row, html);
        nr?.querySelector('input:not([type=hidden]), select')?.focus();
      }
      return;
    }
    const cancel = e.target.closest('[data-cancel]');
    if (cancel) {
      const row = cancel.closest('tr');
      const orig = originals.get(row.dataset.rowId);
      if (orig) {
        e.preventDefault();
        replaceRow(row, orig);
      }
    }
  });

  document.addEventListener('submit', async (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-inline')) return;
    if (form.dataset.confirm && !form.dataset.confirmed) return; // 確認ダイアログの後に来る
    e.preventDefault();
    const row = document.querySelector(`tr[data-row-id="${form.dataset.inline}"]`) ?? form.closest('tr');
    row?.classList.add('is-saving');
    const { status, html } = await fetchFragment(form.action, { method: 'POST', body: new FormData(form, e.submitter) });
    if (status === 200 || status === 422) {
      const nr = replaceRow(row, html);
      if (status === 200) {
        nr?.classList.add('is-saved');
        originals.delete(form.dataset.inline);
      } else {
        nr?.querySelector('[aria-invalid="true"]')?.focus();
      }
    } else {
      row?.classList.remove('is-saving');
      alert('保存できませんでした。画面を読み込み直してください。');
    }
  });

  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    const row = e.target.closest?.('tr.is-editing');
    row?.querySelector('[data-cancel]')?.click();
  });
}
