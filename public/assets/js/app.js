// 読み込み口。JS は「使いやすくする」ためだけ（JS が動かなくても入力と保存はできる。DESIGN.md 1.3）
// 読み込む部品にも app.js と同じ版（?v=）を付ける。付けないとサーバー更新後も古い JS がキャッシュから使われる
const V = new URL(import.meta.url).search;
const [{ initInlineEdit }, { toast }, { confirmDialog }, { initDecimalInputs }] = await Promise.all(
  ['inline-edit', 'toast', 'dialog', 'decimal-input'].map((n) => import(`./${n}.js${V}`)),
);

const $ = (s, el = document) => el.querySelector(s);
const $$ = (s, el = document) => [...el.querySelectorAll(s)];

// メニュー（641〜1023px）
const menuBtn = $('[data-menu]');
const sidebar = $('#sidebar');
menuBtn?.addEventListener('click', () => {
  const open = sidebar.classList.toggle('is-open');
  menuBtn.setAttribute('aria-expanded', String(open));
});

// コードチップ: クリックでコピー
document.addEventListener('click', async (e) => {
  const chip = e.target.closest('[data-copy]');
  if (!chip) return;
  try {
    await navigator.clipboard.writeText(chip.dataset.copy);
    toast(`${chip.dataset.copy} をコピーしました`);
  } catch { /* コピーできない環境では何もしない */ }
});

// 削除などの確認（<form data-confirm="…">）
document.addEventListener('submit', async (e) => {
  const form = e.target;
  if (!(form instanceof HTMLFormElement)) return;
  if (form.dataset.confirm && !form.dataset.confirmed) {
    e.preventDefault();
    const ok = await confirmDialog(form.dataset.confirmTitle || '確認', form.dataset.confirm, form.dataset.confirmOk || '実行する');
    if (ok) {
      form.dataset.confirmed = '1';
      form.requestSubmit(e.submitter ?? undefined);
    }
    return;
  }
  // 二重送信の防止: 押した瞬間に無効化＋「保存中…」
  const btn = e.submitter;
  if (btn && !form.hasAttribute('data-inline') && !e.defaultPrevented) {
    setTimeout(() => {
      btn.disabled = true;
      btn.dataset.label = btn.textContent;
      btn.textContent = '保存中…';
    }, 0);
  }
}, true);

// 未保存の変更があるまま離れるときだけ確認
$$('form[data-guard]').forEach((form) => {
  let dirty = false;
  form.addEventListener('input', () => { dirty = true; });
  form.addEventListener('submit', () => { dirty = false; });
  window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
});

// サーバーからのトースト（data-toast）
const t = $('[data-toast]');
if (t) toast(t.dataset.toast);

initInlineEdit();
initDecimalInputs();

// 選んだらすぐ表示を切り替える（<select data-autosubmit>）。インラインの onchange は CSP で使えないため
document.addEventListener('change', (e) => {
  if (e.target.matches('[data-autosubmit]')) e.target.form?.requestSubmit();
});

// 画面ごとの機能（必要なときだけ読み込む）
const page = document.body.querySelector('[data-page]')?.dataset.page;
const modules = {
  'analysis-input': './analysis-input.js',
  'analysis-chart': './chart-setup.js',
  'bottling': './bottling-calc.js',
  'process': './process-form.js',
  'master': './code-preview.js',
};
if (page && modules[page]) {
  import(modules[page] + V).then((m) => m.init?.());
}

// 一覧で "/" を押すと検索欄へ
document.addEventListener('keydown', (e) => {
  if (e.key === '/' && !e.target.closest('input, textarea, select')) {
    const q = $('[data-search]');
    if (q) { e.preventDefault(); q.focus(); }
  }
});
