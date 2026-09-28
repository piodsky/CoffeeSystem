// End-to-end test for Inventory + Customers (Phase 3), as admin, in headless Edge.
//   node tests/e2e-admin.mjs [output-dir]
// NOTE: it changes stock and adds/deletes a product. Re-import database.sql afterwards.
import { tmpdir } from 'node:os';
import { join, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { openBrowser, BASE, sleep } from './lib/browser.mjs';

const OUT = process.argv[2] || join(tmpdir(), 'brewbean-e2e');
const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const b = await openBrowser(OUT, 9335);
const { send, evaluate, waitFor, shot, type, size, nav, text, check, login, reportProblems, close } = b;

const flash = () => text('.alert span');
const rowFor = (name) => `[...document.querySelectorAll('tbody tr')].find((tr) => tr.querySelector('.item-cell__name')?.textContent.trim() === ${JSON.stringify(name)})`;
const submitAndWait = async (formExpr) => {
  await evaluate(`window.__old = true; ${formExpr}.requestSubmit()`);
  await waitFor('window.__old === undefined && document.readyState === "complete"', 'form submit navigation');
  await sleep(200);
};

try {
  await size(1536, 1024);
  await login('admin', 'admin123');

  // --- Icon sprite must be valid XML (a broken sprite hides icons silently) ---
  const spriteOk = await evaluate(`fetch(${JSON.stringify(BASE + '/assets/img/icons.svg')}).then(r => r.text()).then(t => {
    const doc = new DOMParser().parseFromString(t, 'image/svg+xml');
    return !doc.querySelector('parsererror') && doc.querySelectorAll('symbol').length;
  })`);
  check(spriteOk > 30, `icons.svg parses as valid XML (${spriteOk} icons)`);

  // --- POS shows product images ---
  await waitFor(`document.querySelectorAll('.product-card img').length > 0`, 'POS images');
  check(await evaluate(`[...document.querySelectorAll('.product-card img')].every(i => i.complete && i.naturalWidth > 0)`), 'POS product images load');
  await shot('p3-1-pos-images');

  // --- Inventory list ---
  await nav(`${BASE}/pages/inventory.php`);
  check(await evaluate(`document.querySelectorAll('tbody tr').length`) === 15, 'inventory shows 15 rows per page');
  check((await text('.pager__info')).endsWith('of 18'), 'pager says "of 18" → ' + await text('.pager__info'));
  await shot('p3-2-inventory');

  // --- Adjust stock dialog: remove 3 Iced Latte (damaged) ---
  const before = Number(await evaluate(`${rowFor('Iced Latte')}.querySelector('[data-adjust]').dataset.stock`));
  await evaluate(`${rowFor('Iced Latte')}.querySelector('[data-adjust]').click()`);
  check(await evaluate(`document.getElementById('adjustDialog').open`), 'Adjust button opens the stock dialog');
  check((await text('#adjustName')).startsWith('Iced Latte'), 'dialog shows the product name');
  await evaluate(`document.querySelector('#adjustDialog [value=remove]').click()`);
  check(await evaluate(`document.querySelector('#adjustReason option[value=restock]').disabled`), '"Restock" reason is disabled when removing');
  await type('3');
  check((await text('#adjustPreview')) === `New stock will be ${before - 3}.`, 'dialog previews the new stock');
  await evaluate(`document.getElementById('adjustReason').value = 'damaged'`);
  await shot('p3-3-adjust-dialog');
  await submitAndWait(`document.querySelector('#adjustDialog form')`);
  check((await flash()).includes(`Stock is now ${before - 3}`), 'stock adjusted → ' + await flash());

  // --- Add product with image ---
  await nav(`${BASE}/pages/product-form.php`);
  await submitAndWait(`document.querySelector('.form-layout')`);
  check(await evaluate(`document.querySelectorAll('[aria-invalid="true"]').length`) >= 3, 'empty form shows field errors');
  await shot('p3-4-form-errors');

  const fill = (name, value) => evaluate(`document.querySelector('[name=${name}]').value = ${JSON.stringify(value)}`);
  await fill('name', 'Spanish Latte');
  await fill('category_id', '1');
  await fill('price', '135');
  await fill('code', 'cf-009');
  await fill('barcode', '4800000000200');
  await fill('stock', '20');
  const { result } = await send('Runtime.evaluate', { expression: `document.getElementById('imageInput')` });
  await send('DOM.setFileInputFiles', {
    objectId: result.objectId,
    files: [join(ROOT, 'assets/uploads/products/sample-cf-005.png')],
  });
  await waitFor(`document.getElementById('imagePreviewImg').src.startsWith('blob:')`, 'image preview');
  check(true, 'choosing a file shows a preview');
  await shot('p3-5-product-form');
  await submitAndWait(`document.querySelector('.form-layout')`);
  check((await flash()) === 'Spanish Latte was added to the inventory.', 'product added → ' + await flash());

  await nav(`${BASE}/pages/inventory.php?search=spanish`);
  check(await evaluate(`${rowFor('Spanish Latte')}.querySelector('.thumb img')?.naturalWidth > 0`), 'new product has its uploaded image');
  check(await evaluate(`${rowFor('Spanish Latte')}.textContent.includes('CF-009')`), 'code saved in upper case (CF-009)');

  // --- Edit page: history + image ---
  await evaluate(`${rowFor('Spanish Latte')}.querySelector('.item-cell__name').click()`);
  await sleep(300);
  await waitFor(`document.readyState === 'complete' && location.pathname.endsWith('product-form.php')`, 'edit page');
  check((await text('.history-card tbody tr td:nth-child(2)')) === 'Opening stock', 'stock history starts with "Opening stock"');
  await shot('p3-6-product-edit');

  // --- Delete it (never sold) ---
  await nav(`${BASE}/pages/inventory.php?search=spanish`);
  await evaluate(`window.confirm = () => true`);
  await submitAndWait(`${rowFor('Spanish Latte')}.querySelector('form[data-confirm]')`);
  check((await flash()) === 'Spanish Latte was deleted.', 'unsold product can be deleted');
  await nav(`${BASE}/pages/inventory.php?search=latte`);
  check(await evaluate(`${rowFor('Iced Latte')}.querySelector('form[data-confirm]') === null`), 'sold products have no Delete button');

  // --- Customers ---
  await nav(`${BASE}/pages/customers.php`);
  check(await evaluate(`document.querySelectorAll('tbody tr').length`) >= 4, 'customers list shows the customers');
  await shot('p3-7-customers');
  await nav(`${BASE}/pages/customer-form.php?id=2`);
  check((await text('.mini-stats div:nth-child(1) strong')) === '1', 'Maria Santos shows 1 visit');
  await shot('p3-8-customer');

  // Responsive: no horizontal scroll on a tablet
  await size(1024, 900);
  await nav(`${BASE}/pages/inventory.php`);
  check(await evaluate(`document.documentElement.scrollWidth <= window.innerWidth`), 'inventory: no horizontal page scroll at 1024px');
  await shot('p3-9-inventory-1024');

  reportProblems();
} catch (err) {
  console.log('ERROR ' + err.message);
  await shot('error').catch(() => {});
  console.log(b.problems.join('\n'));
  process.exitCode = 1;
} finally {
  close();
}
