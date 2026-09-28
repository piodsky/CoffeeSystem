# CoffeeSystem: project memory for Claude

Brew & Bean Coffee Shop POS. PHP 8.2 (XAMPP) + PDO + MariaDB 10.4, no framework, plain CSS, vanilla JS.
DB: `coffee_db`, root / no password. URL: http://localhost/CoffeeSystem. UI follows the brown/cream mockup
(dark topbar with logo/search/date/time/user/logout, dark sidebar, cream content).
Read this first; open only the files a task needs.

## Phase status
- [x] Phase 1: database.sql, config, auth (login/logout/roles/throttle), layout, placeholder pages
- [x] Phase 2: POS screen `pages/pos.php` + `assets/js/pos.js` + `assets/css/pos.css`; APIs `api/pos/products.php`,
      `api/pos/checkout.php`, `api/customers/create.php`; receipt `pages/receipt.php` (+ receipt.css/js)
- [x] Phase 3: Inventory `pages/inventory.php` (list + POST actions toggle/delete/adjust) + `pages/product-form.php`;
      Customers `pages/customers.php` + `pages/customer-form.php` (purchase history). Classes `Products`,
      `ImageUpload`, `Customers`. Stock audit table `stock_movements` (migration `migrations/003_phase3_inventory.sql`).
      18 generated sample illustrations `assets/uploads/products/sample-<code>.png`.
- [ ] Phase 4: Sales History (reprint via `pages/receipt.php?id=`, void completed sale = status cancelled + restock
      + `Products::log(..., 'void', +qty, ...)`), Reports (daily sales, top items; old Phase 1 dashboard stats),
      Settings (settings table, users CRUD with password_hash). Existing DBs need a migration file, not a re-import.

## User decisions (don't revert)
- **No auto-logout.** User said "don't use session expired". `SESSION_IDLE_TIMEOUT=0`,
  `SESSION_ABSOLUTE_TIMEOUT=0` (0 = off). Never show "session expired" wording.
- Build in phases, zip + XAMPP setup steps each phase (zip goes to `~/Downloads/CoffeeSystem-phaseN.zip`).
- Keep folders clean (`config/ system/ includes/ pages/ api/ assets/ storage/ tests/`, `.env`).

## POS behaviour (Phase 2)
- Buttons: **Save** = payment dialog → complete sale (deduct stock). **Print** = same + auto-print; with an empty
  cart it reprints the last sale. **New Sale** / **Cancel** clear the cart (confirm). `held` status is unused so far.
- Cart lives in the browser (+ localStorage `bb.pos.<userId>`); the server only receives product_id + qty and
  recomputes everything in `Sales::complete()` (transaction, `SELECT … FOR UPDATE`, `stock >= ?` guard).
- Receipt printing: hidden iframe loads `receipt.php?id=X&autoprint=1`; receipt.php calls `allow_same_origin_framing()`.
- Keys: F2 scan (focus search), F3 search, F4 add highlighted card, ↑/↓ move highlight, Enter = exact barcode/code
  match else highlighted. Typing anywhere (scanner) goes to the search box.

## Inventory / Customers behaviour (Phase 3)
- Stock changes ONLY via sales or `Products::adjustStock()` (reasons in `Products::REASONS`, direction-checked);
  every change writes `stock_movements` (type initial/sale/restock/adjustment/void, signed qty, stock_after).
  The product edit form never edits stock; opening stock is set on create only.
- Delete is allowed only if never sold / never bought; otherwise deactivate (`is_active=0` hides from POS).
- Customers: admin + cashier can add/edit; only admin can deactivate/delete. Duplicate phone numbers are rejected.
- Images: `ImageUpload::store($_FILES['image'])` (finfo + getimagesize, 2 MB, ≤4000px, random hex name);
  `ImageUpload::url()`; `ImageUpload::delete()` only deletes hex names (never the bundled sample-*.png).
  Store only after other fields validate; delete the new file if the DB save fails; delete the old one after success.

## Conventions
- Every entry file starts with `require __DIR__ . '/../system/bootstrap.php';` Classes in `system/` autoload.
- Page: `$page = require_page('<key>');` (login + role from `config/menu.php`), then
  `require ROOT_PATH.'/includes/header.php'` … `footer.php`. Optional `$pageStyles`/`$pageScripts` arrays.
  Non-menu pages: `Auth::requireRole('admin','cashier');`
- API: `api_guard('POST', ['admin','cashier']);` (method + login + role + CSRF header), `$data = request_json();`,
  reply `json_response(['ok'=>true,...])`. For errors, **throw `new HttpException(status, msg, details)`**; the global
  handler turns it into JSON (API) or the error page, and rolls nothing back itself, so domain code does that.
  JS: `await BB.api('pos/checkout.php', {method:'POST', body})` (throws Error with .status/.data), `BB.toast(msg, type)`.
- Every query goes through `db()->prepare()->execute()`, including ones with no params. Money math is done in integer cents: `to_cents()`,
  `from_cents()`, `money()`. Settings: `setting('vat_rate')`. Sales must use a transaction.
- Escape all output with `e()`. JS builds DOM from `<template>` + `textContent` (no innerHTML with data).
  Icons: `icon('name')` (sprite `assets/img/icons.svg`, ids `i-<name>`); in JS `svgIcon(name)`.
- CSP is strict: **no inline `<script>`, no `style=""` attributes, no CDNs**. Put CSS/JS in assets.
- Errors: `abort(code, msg, ?title)`. Don't use HTTP 419 (Apache turns it into 500); CSRF failures use 403.
- Dialogs: `<dialog class="modal">`, any `[data-close]` button closes it (app.js).
- Server-rendered CRUD forms (PRG): on error `flash_old(...)`, `flash_errors([...])`, redirect back; in the template
  `old('x', $default)`, `has_old()`, `<input …<?= invalid('x') ?>>` + `<?= field_error('x') ?>`. Classes:
  `.form-grid/.form-field/.form-label/.form-input`, `.card--pad`, `.form-layout` + `.form-side`.
  Row actions: small POST forms with CSRF + hidden `return` (validated by `safe_return()`); destructive ones get
  `data-confirm="…"` (app.js asks). Lists: `paginate($total)`, `includes/pagination.php` ($pg, $pgPath, $pgQuery),
  search param is `search` (not `q`, which belongs to the header search), `like_pattern()` for LIKE.
- Shortcuts: app.js dispatches cancelable `bb:shortcut` event ({key:'F2'|'F3'|'F4'}); page JS calls preventDefault to take over.

## Schema notes
- `sales.status`: held (unused) / completed (stock deducted) / cancelled (void).
  `customer_id NULL` = Walk-in. `sale_no` = 7-digit zero-padded id ('0000005'), set right after insert.
- VAT 12% applied on (subtotal − discount); rate lives in `settings.vat_rate`.
- `sale_items` stores code/name/price snapshots. Products are soft-deleted (`is_active=0`).
- `products.stock` has CHECK >= 0; `reorder_level` drives low-stock pills.
- Category icons: coffee, glass, croissant, cookie, bag.

## Testing
- Lint: `C:\xampp\php\php.exe -l file.php`
- **E2E: `node tests/e2e-pos.mjs [outdir]`** (28 checks) and **`node tests/e2e-admin.mjs [outdir]`** (22 checks,
  Phase 3 + icons.svg XML validity). Shared helpers in `tests/lib/browser.mjs` (login, nav, key, type, check, shot).
  Both change data, so check `SELECT MAX(id) FROM sales` is 4 (sample only) before re-importing
  `C:\xampp\mysql\bin\mysql.exe -u root < database.sql` (drops tables!). If the user has real data, don't re-import.
- File uploads in tests: `DOM.setFileInputFiles` with an objectId (see e2e-admin.mjs). With curl, use Windows paths.
- Editing `assets/img/icons.svg`: keep a space between attributes; an XML error silently breaks later icons.
- API tests: curl + cookie jar; CSRF from `<meta name="csrf-token">` sent as `X-CSRF-Token`.
- Headless Edge has a ~500px minimum viewport, so phone-width screenshots crop rather than show the real layout.
