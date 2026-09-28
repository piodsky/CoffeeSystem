# Brew & Bean Coffee Shop — POS System

PHP 8 + PDO + MySQL point-of-sale for a coffee shop. No framework, plain CSS, vanilla JS.

## Setup on XAMPP (Windows)

1. **Copy the folder**: put `CoffeeSystem` in `C:\xampp\htdocs\` so you have `C:\xampp\htdocs\CoffeeSystem\index.php`.
2. **Start services**: open the XAMPP Control Panel and start **Apache** and **MySQL**.
3. **Create/import the database**
   - Open http://localhost/phpmyadmin, then **Import**, choose `database.sql` and click **Go**.
     (The file creates `coffee_db` if it doesn't exist.)
   - Or from a terminal:
     `C:\xampp\mysql\bin\mysql.exe -u root < C:\xampp\htdocs\CoffeeSystem\database.sql`
   - ⚠ Re-importing **drops and recreates all tables** (all data is reset).
4. **Check `.env`**: the defaults match stock XAMPP (`root`, empty password, `coffee_db`).
   If you set a MySQL password, put it in `DB_PASS=`. If `.env` is missing, copy `.env.example` to `.env`.
5. **Open** http://localhost/CoffeeSystem and sign in:

   | Username | Password     | Role    |
   |----------|--------------|---------|
   | admin    | admin123     | Admin   |
   | cashier  | cashier123   | Cashier |

   **Change these passwords before real use** (Settings → Users arrives in Phase 4).

### Upgrading an existing install (keeps your data)
Copy the new files over the old folder, then run the migrations you haven't run yet. They only **add**
tables and columns and can safely be run twice:

```
C:\xampp\mysql\bin\mysql.exe -u root coffee_db < C:\xampp\htdocs\CoffeeSystem\migrations\003_phase3_inventory.sql
```
(Or in phpMyAdmin: select `coffee_db` → **Import** → the migration file.)

### Using it from other devices on the shop network
Set `APP_URL=http://<PC-IP>/CoffeeSystem` in `.env` (for example `http://192.168.1.10/CoffeeSystem`) and allow
Apache through Windows Firewall. Links are host-relative, so tablets on the LAN work as well.

### Going live checklist
- `.env`: `APP_ENV=production`, `APP_DEBUG=false`
- Give MySQL a dedicated user with a strong password (not `root`) and put it in `.env`
- Use HTTPS if the POS is reachable beyond the shop network (session cookies then become `Secure` automatically)
- Optional auto-logout: set `SESSION_IDLE_TIMEOUT` (seconds). `0` = stay signed in until Logout.

## Using the POS

| Action | How |
|---|---|
| Add an item | Click a product card, **or** scan its barcode, **or** type a name and press **Enter** |
| Scan barcode | **F2**, then scan. (Scanning works even without F2, because typing goes straight to the search box) |
| Search | **F3**, type; **↑ / ↓** to pick; **F4** or **Enter** adds the highlighted item |
| Change quantity | − / + buttons or type the number (can't exceed stock) |
| Discount | Type a % in *Discount*. VAT 12% is applied after the discount |
| **Save** | Opens payment: enter the cash received (or tap a quick amount), shows the change → completes the sale and deducts stock |
| **Print** | Same as Save, then prints the receipt. With an empty cart it reprints the last sale |
| **New Sale / Cancel** | Clears the current cart (asks first) |
| New customer | **+** next to *Customer* |

Out-of-stock items can't be added, and the server re-checks stock when saving. If two tills sell the last
item at the same moment, only one sale goes through.

**Receipt printer:** receipts are laid out for 80 mm thermal paper. In the Chrome/Edge print dialog choose the
receipt printer, set *Margins: None* and untick *Headers and footers* (the browser remembers this).
For one-click printing without the dialog, start Edge/Chrome on the till PC with `--kiosk-printing`.

## Inventory & Customers

- **Inventory** (admin): search by name/code/barcode, filter by category or *Low stock / Out of stock*.
  - **Add / Edit product**: name, category, price, code, barcode, description, image, low-stock alert level.
  - **Adjust stock** (box icon): add or remove with a reason (restock, damaged, expired, count correction…).
    Every change, including each sale, is kept in the product's **Stock History** with who did it.
  - **Deactivate** (power icon) hides a product from the POS but keeps its history. **Delete** only appears for
    products that were never sold.
  - **Images**: JPG, PNG or WebP up to 2 MB (square looks best). The sample products come with original
    illustrations; replace them with your own photos anytime.
- **Customers** (admin + cashier): add/edit, search by name/phone/email, see visits, total spent and past receipts.
  Only an admin can deactivate or delete a customer; customers with purchases can only be deactivated.

## Folder structure

```
CoffeeSystem/
├── .env / .env.example   Secrets & environment settings (blocked from the web)
├── .htaccess             Blocks dotfiles, .sql, .md; no directory listing
├── index.php             Redirects to login or POS
├── login.php / logout.php
├── database.sql          Schema + sample data (fresh install)
├── migrations/           Upgrade scripts for existing databases                 [web-blocked]
├── config/               app.php, database.php, menu.php (menu + page roles)   [web-blocked]
├── system/               Core: bootstrap, Env, Database, Session, Csrf, Auth, helpers,
│                         Sales, Products, Customers, ImageUpload, HttpException [web-blocked]
├── includes/             Layout partials: header, sidebar, footer, flash, error [web-blocked]
├── pages/                One file per screen (pos, receipt, inventory, product-form, customers, ...)
├── api/                  JSON endpoints: pos/products, pos/checkout, customers/create
├── assets/               css/, js/, img/ (icons.svg sprite), uploads/products/ (product images)
├── storage/logs/         Error logs                                        [web-blocked]
└── tests/                Browser tests: e2e-pos.mjs, e2e-admin.mjs              [web-blocked]
```

## Security built in
- `password_hash` / `password_verify`; automatic rehash; timing-safe login (no username enumeration)
- Login throttling: 5 failures per username+IP → 15 min lockout (configurable in `.env`)
- PDO with real prepared statements (emulation off) and MySQL strict mode
- CSRF token on every form and on AJAX (`X-CSRF-Token` header); logout is POST-only
- All output escaped with `e()` (`htmlspecialchars`)
- Sessions: strict mode, HttpOnly, SameSite=Lax, path-scoped cookie, new ID at login and every 15 min,
  bound to the browser user-agent; optional idle/absolute timeout
- Roles checked on every page (`require_page()`) and API endpoint (`api_guard()`)
- Security headers: CSP (no inline scripts/styles), X-Frame-Options, nosniff, no-store cache
- Uploads folder serves images only; scripts can never run there

## Roadmap
- [x] **Phase 1**: database, config, login/logout, roles, layout (sidebar + header)
- [x] **Phase 2**: POS screen: category tabs, product grid, search/barcode, cart, discount, VAT, checkout
      (transaction + stock deduction), Print receipt, Cancel, F2/F3/F4, quick-add customer
- [x] **Phase 3**: Inventory CRUD with safe image uploads, stock adjustments + audit log; Customers CRUD with purchase history
- [ ] **Phase 4**: Sales History, Reports (daily sales, top items), Settings & user management
