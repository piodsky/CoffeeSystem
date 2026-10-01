// End-to-end POS test — drives headless Edge through the DevTools protocol.
//   node tests/e2e-pos.mjs [output-dir]
// Signs in as cashier, uses the POS like a person would (clicks, F2/F3/F4, Enter),
// completes a sale and saves screenshots to output-dir (default: system temp).
// NOTE: it creates real sales and lowers stock. Re-import database.sql afterwards.
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { openBrowser, BASE, sleep } from './lib/browser.mjs';

const OUT = process.argv[2] || join(tmpdir(), 'execom-e2e');
const b = await openBrowser(OUT);
const { send, evaluate, waitFor, shot, key, type, size, nav, text, check, login, problems, reportProblems, close } = b;
const cartQty = (name) => evaluate(`(() => {
  const row = [...document.querySelectorAll('#cartBody tr')].find((tr) => tr.querySelector('strong').textContent === ${JSON.stringify(name)});
  return row ? Number(row.querySelector('input').value) : 0;
})()`);

try {
  await size(1536, 1024);

  // --- Login through the real form ---
  await login('cashier', 'cashier123');
  await waitFor(`document.querySelectorAll('.product-card').length > 0`, 'products loaded');
  check(await evaluate(`document.querySelectorAll('.product-card').length`) === 12, 'grid shows 12 products');

  // --- Category tab ---
  await evaluate(`document.querySelector('[data-category="2"]').click()`);
  check(await evaluate(`document.querySelectorAll('.product-card').length`) === 5, 'Peripherals tab shows 5 products');
  await evaluate(`document.querySelector('[data-category="all"]').click()`);

  // --- Click cards (mockup cart: Laptop, Mouse, RAM 8GB; the 2nd Mouse is scanned below) ---
  for (const n of ['Laptop', 'Mouse', 'RAM 8GB']) {
    await evaluate(`[...document.querySelectorAll('.product-card')].find(c => c.querySelector('.product-card__name').textContent === ${JSON.stringify(n)}).click()`);
  }
  check(await text('#tSubtotal') === '₱ 27,150.00', 'subtotal ₱ 27,150.00 after 3 clicks');

  // --- F2 + barcode scan + Enter (Mouse barcode) → cart now matches the mockup ---
  await key('F2', 'F2', 113);
  check(await evaluate(`document.activeElement.id === 'globalSearch'`), 'F2 focuses the search box');
  await type('4806500000028'); await key('Enter', 'Enter', 13);
  check(await cartQty('Mouse') === 2, 'scanned barcode adds Mouse (qty 2)');
  check(await evaluate(`document.getElementById('globalSearch').value`) === '', 'search box cleared after scan');
  check(await text('#tSubtotal') === '₱ 27,500.00', 'subtotal ₱ 27,500.00 (mockup)');
  check(await text('#tVat') === '₱ 3,300.00', 'VAT ₱ 3,300.00 (mockup)');
  check(await text('#tTotal') === '₱ 30,800.00', 'total ₱ 30,800.00 (mockup)');
  await evaluate(`document.activeElement.blur()`);
  await shot('1-pos-cart');

  // --- F3 search + F4 add highlighted ---
  await key('F3', 'F3', 114);
  await type('or');
  const visibleNames = await evaluate(`[...document.querySelectorAll('.product-card__name')].map(e => e.textContent)`);
  check(JSON.stringify(visibleNames) === JSON.stringify(['Monitor 24"', 'Network Switch']), 'search "or" filters grid → ' + visibleNames.join(', '));
  await key('ArrowDown', 'ArrowDown', 40);
  await key('F4', 'F4', 115);
  check(await cartQty('Network Switch') === 1, 'ArrowDown + F4 adds highlighted Network Switch');
  await key('Escape', 'Escape', 27);

  // --- Qty stepper, stock limit, remove ---
  const row = (n) => `[...document.querySelectorAll('#cartBody tr')].find(tr => tr.querySelector('strong').textContent === '${n}')`;
  await evaluate(`${row('Network Switch')}.querySelector('[data-act=inc]').click()`);
  check(await cartQty('Network Switch') === 2, '+ button increases qty');
  await evaluate(`(() => { const i = ${row('Network Switch')}.querySelector('input'); i.value = '500'; i.dispatchEvent(new Event('change', {bubbles:true})); })()`);
  check(await cartQty('Network Switch') === 15, 'typing qty 500 is capped at stock (15)');
  await evaluate(`${row('Network Switch')}.querySelector('[data-act=remove]').click()`);
  check(await cartQty('Network Switch') === 0, 'trash button removes the line');

  // --- Discount ---
  await evaluate(`(() => { const d = document.getElementById('discountInput'); d.value = '10'; d.dispatchEvent(new Event('input', {bubbles:true})); })()`);
  // cart: Laptop 25,000 + Mouse 2x350 + RAM 1,800 = 27,500; -10% = 24,750; VAT 2,970; total 27,720
  check(await text('#tTotal') === '₱ 27,720.00', 'discount 10% → total ₱ 27,720.00 (got ' + await text('#tTotal') + ')');

  // --- Refresh keeps the cart ---
  await nav(`${BASE}/pages/pos.php`);
  await waitFor(`document.querySelectorAll('#cartBody tr').length === 3`, 'cart restored after refresh');
  check(await text('#tTotal') === '₱ 27,720.00', 'cart + discount survive a page refresh');

  const mouseStock = await evaluate(`[...document.querySelectorAll('.product-card')].find(c => c.querySelector('.product-card__name').textContent === 'Mouse').querySelector('.stock-pill').textContent.replace(/[^0-9]/g, '')`).then(Number);
  // --- Save → payment dialog ---
  await evaluate(`document.getElementById('btnSave').click()`);
  check(await evaluate(`document.getElementById('payDialog').open`), 'Save opens payment dialog');
  await type('400'); // short
  check(await text('#payChangeLabel') === 'Short by', 'shows "Short by" when cash is too low');
  await key('Enter', 'Enter', 13);
  await sleep(200);
  check(await evaluate(`!document.getElementById('payError').hidden`), 'short cash blocked with error');
  await evaluate(`document.querySelector('#quickCash [data-amount="2800000"]').click()`);
  check(await text('#payChange') === '₱ 280.00', 'quick ₱28,000 button → change ₱ 280.00');
  await shot('2-payment');
  await evaluate(`document.getElementById('payForm').requestSubmit()`);
  await waitFor(`document.getElementById('doneDialog').open`, 'sale completed dialog');
  check(await text('#doneChange') === '₱ 280.00', 'done dialog shows change ₱ 280.00');
  const saleNo = await text('#doneNo');
  await shot('3-done');
  await evaluate(`document.getElementById('doneNew').click()`);
  await sleep(300);
  check(await evaluate(`document.querySelectorAll('#cartBody tr').length`) === 0, 'cart cleared after sale');
  check(await text('#saleNo') !== saleNo, `sale number advanced past ${saleNo} → ${await text('#saleNo')}`);
  await waitFor(`[...document.querySelectorAll('.product-card')].some(c => c.querySelector('.product-card__name').textContent === 'Mouse' && c.querySelector('.stock-pill').textContent === 'In Stock: ${mouseStock - 2}')`, 'Mouse stock refreshed');
  check(true, `grid stock refreshed (Mouse ${mouseStock} → ${mouseStock - 2})`);

  // --- Print last sale → receipt iframe loads ---
  await evaluate(`window.__printed = 0`);
  await evaluate(`document.getElementById('btnPrint').click()`);
  await waitFor(`(() => { try { return document.getElementById('receiptFrame').contentDocument.querySelector('.receipt') !== null; } catch (e) { return false; } })()`, 'receipt iframe loaded');
  check(await evaluate(`document.getElementById('receiptFrame').contentDocument.body.textContent.includes(${JSON.stringify(saleNo)})`), 'Print loads receipt for ' + saleNo + ' in hidden iframe');

  // --- Cancel ---
  await evaluate(`document.querySelector('.product-card').click()`);
  await evaluate(`window.confirm = () => true; document.getElementById('btnCancel').click()`);
  check(await evaluate(`document.querySelectorAll('#cartBody tr').length`) === 0, 'Cancel clears the sale');

  // --- Quick add customer ---
  await evaluate(`document.getElementById('addCustomerBtn').click()`);
  await type('Paolo Garcia');
  await evaluate(`document.getElementById('customerForm').requestSubmit()`);
  await waitFor(`!document.getElementById('customerDialog').open`, 'customer dialog closes');
  check(await evaluate(`document.getElementById('customerSelect').selectedOptions[0].text`) === 'Paolo Garcia', 'new customer added and selected');

  // --- Empty-cart screenshot + responsive ---
  await evaluate(`document.activeElement.blur()`);
  for (const n of ['Laptop', 'Mouse', 'RAM 8GB']) await evaluate(`[...document.querySelectorAll('.product-card')].find(c => c.querySelector('.product-card__name').textContent === '${n}').click()`);
  await shot('4-pos-1536');
  await size(1024, 900);
  await sleep(300);
  check(await evaluate(`document.documentElement.scrollWidth <= window.innerWidth`), 'no horizontal scroll at 1024px');
  await shot('5-pos-1024');
  await size(1536, 1024);

  // --- Receipt page ---
  const lastId = await evaluate(`JSON.parse(localStorage.getItem('bb.pos.2')).lastSale.id`);
  await nav(`${BASE}/pages/receipt.php?id=${lastId}`);
  await shot('6-receipt');

  reportProblems();
} catch (err) {
  console.log('ERROR ' + err.message);
  await shot('error').catch(() => {});
  console.log(problems.join('\n'));
  process.exitCode = 1;
} finally {
  close();
}
