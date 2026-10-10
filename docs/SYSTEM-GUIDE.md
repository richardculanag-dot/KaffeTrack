# KaffeTrack — Complete System Guide

> **KaffeTrack: A Café POS and Inventory System for HEIM Café** (Matina, Davao City, Philippines).
> A full-stack point-of-sale, inventory, finance and reporting web application for running a café
> day to day, following Philippine retail and tax rules.

This is the single reference for the whole web project — what it does, who uses it, every module,
how data flows, the architecture, the database/ERD, the ORM mapping, and the non-obvious rules
you must know before changing anything.

**Other docs in this project:** [SETUP.md](SETUP.md) (installation & deployment),
[TESTING.md](TESTING.md) (executed test record), [HCI-DESIGN.md](HCI-DESIGN.md) (usability rationale),
and [erd/](erd/) (rendered ER diagrams).

---

## 1. What the system does

KaffeTrack digitises the entire operation of a single café:

| Concern | How KaffeTrack handles it |
| --- | --- |
| Selling | Touch-first POS with product cards, sizes (variants), add-ons, order notes, dine-in/takeout |
| Orders | Queue number lifecycle: pending → ready → completed (call the number, hand over) |
| Money | Cash drawer (shift) tracking with petty cash in/out, denomination counts, over/short variance |
| Payments | Cash, card (Visa/Mastercard/JCB/Amex…), online (GCash/Maya/QR Ph…); unpaid orders collect later |
| Refunds | Full-order refunds requiring a **manager PIN**, throttled against PIN guessing |
| Discounts | Senior Citizen / PWD (RA 9994 / RA 10754 style VAT-exempt 20%), employee, promos — with ID-number capture |
| Inventory | "Open-container" model: supplier deliveries → batches (FEFO + expiry) → one unit opened at a time at the bar |
| Finance | Other income + operating expenses; stock purchases auto-flow into an "Inventory Purchases" expense |
| Insight | Manager dashboard with KPIs, Chart.js graphs, peak hours, best sellers, cashier leaderboard |
| Compliance | 6 PDF reports (DomPDF), 12%-VAT breakdowns, VAT-exempt sales lines, audit trail of every action |
| Accountability | Append-only audit log of logins, edits, voids, refunds, write-offs; soft-delete Archive with restore |

**Deliberate design choice:** menu products are **not** linked to inventory items. Drinks are
hand-made, so per-cup ingredient consumption is not tracked; instead staff control whole containers
(carton, bag, syrup bottle) via the open-container workflow.

---

## 2. Personas (who uses it)

Only two roles exist — `users.role` is either `manager` or `staff`.

### 2.1 Staff (barista / cashier)
- Lands on the **POS** after login; sees only staff-safe nav (POS, Order queue, Cash drawer, Stock on hand, Stock movements, Profile).
- Typical day: open the cash drawer (start a shift) → take & charge orders → mark ready → hand over → stock out a carton when opening one, mark it depleted when empty.
- Can **view** stock and read alerts, but cannot edit items, adjust quantities, or see money settings.
- All manager-only URLs return a custom **403** page (`app/Exceptions` / `errors/403.blade.php`).

### 2.2 Manager (owner / supervisor)
- Lands on the **Dashboard**; sees everything staff sees, plus: Stock in, Suppliers, Products,
  Categories & add-ons, Discounts, Finance, Reports, Users, Audit logs, Archive, Settings.
- Unique powers: **manipulate money records** (finance), **approve refunds by PIN** (their own 4–6
  digit, bcrypt-hashed `users.pin`), **archiving/restoring**, **user administration**, **settings**.
- Guard rails enforced in code: you cannot deactivate/archive yourself, cannot remove the last
  active manager, managers must have a PIN, `closeNotes` must explain any drawer variance.

### 2.3 Account lifecycles & auth
- Login by **username or e-mail, case-insensitive** (providers lowercase on save).
- Brute force: **5 attempts/minute** per login+IP via `RateLimiter`; failures audited (`login_failed`).
- Deactivated or archived users are signed out at the next request (`EnsureUserIsActive`).
- Deleting = "archiving" (soft delete). History everywhere keeps the user snapshot (name rows like
  `user_name`, cashiers use `withTrashed()` relations).

---

## 3. End-to-end system flow

### 3.1 The trading day (staff)

```
Open drawer (cash_shifts, opening_cash)
   │
   ▼
POS ── cart built in the BROWSER (Alpine store) ──► checkout (Livewire call)
   │                                                    │
   │                                                    ▼
   │                              OrderService::place() — DB transaction:
   │                                • re-read prices from DB (browser never trusted)
   │                                • validate item/addon availability
   │                                • OrderPricing::compute() → VAT / discount math
   │                                • ORD-YYYYMMDD-#### + daily queue number (advisory lock)
   │                                • snapshot product/variant/addon names+prices into order_items
   │                                • AuditLog: order_placed
   │                                                    │
   │                       ┌────────────────────────────┴───────────────┐
   │                       ▼                                            ▼
   │              recordPayment() (cash/card/online)            saved as unpaid
   │              payment_status=paid; links to shift           (collect later in queue)
   │                                                    │
   ▼                                                    ▼
Order queue: markReady() → ready_at set → customer called → complete()
   • complete() refuses unpaid orders — it opens the Collect payment modal instead
   • or cancel(reason) — only unpaid pending/ready orders
                                                    │
                                                    ▼
                                        Refund (of a PAID order):
   manager picks staff→selects manager → PIN check (RateLimiter: 5/5 min, refund_denied audited)
   → Refund row (processed_by + approved_by) → order status=refunded, payment_status=refunded
   → cash refunds hit the drawer's expected balance
   │
   ▼
End of day: Cash shift close
   • blocked while ANY pending/ready order exists
   • denominations JSON counted → counted_cash vs expected_cash = variance (over/short)
   • variance ≠ 0 requires an explanation note
   • expected = opening + cash sales − cash refunds + cash in − cash out
```

### 3.2 The inventory flow (deliberately separate from sales)

```
Supplier (master data)
   │  StockInForm (delivery)
   ▼
stock_ins ──► stock_batches (qty, unit_cost, expiry; expiry required if track_expiry)
   │                                    │
   │  total_cost auto-creates an        │
   │  expenses row (stock_in_id FK,     │  FEFO: FIRST-EXPIRED-FIRST-OUT selection
   │  "Inventory Purchases" category)   │  when consuming stock
   ▼                                    ▼
inventory_items.quantity_on_hand ◄── always = Σ batch.quantity_remaining (invariant)
   ▲                                    maintained by InventoryService, rowLocked
   │
   ├─ stock_out (bar) : 1 unit leaves storage → item_usages row (status=in_use)
   │     Only ONE open usage per item — DB partial unique index `item_usages_one_open_per_item`
   │     blocks a second open; racing clicks surface BusinessRuleException messages.
   ├─ mark_depleted : usage closed, status=depleted (fully used)
   ├─ discard(reason) : usage closed, status=discarded (spilled/spoiled — waste)
   └─ manager adjust(): adjustment_in / adjustment_out / waste / expired
         • adjustments create batches with batch_code='ADJ'
         • removals pull from specific batch or FEFO across batches
         • every change writes one stock_movements row (append-only ledger:
           type, signed quantity, balance_after, unit_cost, reference morph, reason)
```

Sales never decrement inventory — the two systems are intentionally decoupled.

### 3.3 The finance flow

- **Sales income** is *derived, not stored*: everything Financial/Analytics does starts from
  `paid` orders (`Analytics::summary()`); there's no duplicate ledger to desync.
- **Other income** (catering etc.) → `incomes` table.
- **Expenses** → `expenses` (manual manager entry) + automatic `Inventory Purchases` expense rows
  created by every stock-in (paid cash/bank/card/online/check), linked via `stock_in_id` —
  these are locked from edit/archive in the Finance UI to preserve the link.
- Net income = sales + other income − expenses (margin % shown on the income statement).

### 3.4 Cross-cutting flow: audit & archive

- Every model except `Order`, `Payment`, `Refund`, `Setting` uses the `Auditable` trait:
  create/update/archive/restore events → single `audit_logs` row with only changed fields as
  old→new pairs; secrets masked as `••••••`; nothing hard-deleted.
- `Order` logs its *workflow* explicitly instead (`order_placed`, `order_ready`, `order_completed`,
  `cancelled`, `refund`) so the log reads like the café story, not like CRUD.
- Login/logout/failed-login events audited from `AppServiceProvider` listeners.
- `Archive` screen can restore any of 11 source kinds; restore checks for name conflicts and
  archived parents before allowing the child back.

---

## 4. Modules → routes → components map

### 4.1 Staff (always visible)

| Route | Module / page | Livewire class | What it does |
| --- | --- | --- | --- |
| `GET /` | Landing redirect | closure | staff → `pos`, manager → `dashboard` |
| `GET /login` | Sign-in | `Auth\Login` | username/email + password, throttled |
| `GET /profile` | My profile | `Profile` | name/email/phone; change password; managers change refund PIN |
| `GET /pos` | **Point of sale** | `Sales\Pos` | product-card cart (Alpine-side); checkout via `checkout()`; receipt; also offers `openShift` |
| `GET /orders` | **Order queue** | `Sales\OrderQueue` | live pending/ready columns + searchable history paginated; mark ready, hand over, cancel, collect payment, refund (PIN), view detail slide-over |
| `GET /orders/{order}/receipt` | Receipt | `ReceiptController` | 80mm thermal-style receipt HTML (print window) with VAT/Senior ID lines |
| `GET /cash-drawer` | **Cash drawer** | `Sales\CashDrawer` | open shift, cash in/out movements, end-of-day denomination count + close, shift history & viewing modal |
| `GET /inventory` | **Stock on hand** | `Inventory\StockOnHand` | item cards with stock status; stock out / mark depleted / discard; per-item history modal (batches, usages) |
| `GET /inventory/movements` | Stock movements | `Inventory\Movements` | two paginated tabs: append-only ledger & open-unit usage history, filter by item/type/user/date |

### 4.2 Manager

| Route | Module / page | Livewire class | What it does |
| --- | --- | --- | --- |
| `GET /dashboard` | Dashboard | `Dashboard` | period filter (today/7d/30d/month), KPI cards, sales+orders chart, peak hours, best sellers, payment mix doughnut, category bars, live queue/shift/low-stock panel, latest orders |
| `GET /inventory/stock-ins` | Stock in (list) | `Inventory\StockIns` | delivery references, costs, batches; viewing modal incl. linked expense |
| `GET /inventory/stock-ins/new` | Receive stock (form) | `Inventory\StockInForm` | multi-line delivery form; pre-fills last unit cost; "add low-stock items from supplier" helper; validates expiry for tracked items, hands to `InventoryService::stockIn()` |
| `GET /inventory/suppliers` | Suppliers | `Inventory\Suppliers` | CRUD + delivery counts/spend |
| `GET /products` | Products | `Products\ProductList` | products CRUD: multi-variant sizes & prices, add-on assignment, photo upload (`public/uploads/products`), archive; 30-day sold counter |
| `GET /products/options` | Categories & add-ons | `Products\Options` | CRUD menu categories (icon, sort) and add-ons |
| `GET /products/discounts` | Discounts | `Products\Discounts` | percent/fixed; flags `is_vat_exempt` (Senior/PWD), `requires_id`; shows 30-day usage |
| `GET /finance` | Finance | `Finance\Overview` | 3 tabs: overview (monthly trend, cashflow, expense doughnut), expenses paginated+category+, income list |
| `GET /reports` | Reports | `Reports\Index` | 6 report types × presets, on-screen preview, download URL |
| `GET /reports/export` | PDF export | `ReportExportController` | streams DomPDF `reports/{type}.blade.php` |
| `GET /users` | Users | `Admin\Users` | create/edit accounts, reset passwords, toggle active, archive |
| `GET /audit-logs` | Audit logs | `Admin\AuditLogs` | filter by module/user/event/date/search; detail modal |
| `GET /archive` | Archive | `Admin\Archive` | 11 restore sources, conflict & parent checks |
| `GET /settings` | Settings | `Admin\Settings` | key/value store `settings` tab: store identity, VAT registered/rate, TIN, receipt footer |

Middleware: `guest` on login; `auth + active` on everything; `role:manager` on the whole second
block. `POST /logout` posts to `LogoutController`. Health check at `/up`.

### 4.3 Navigation definition lives in the layout

The sidebar tree is hardcoded in [`components/layouts/app.blade.php`](../resources/views/components/layouts/app.blade.php)
with manager flags per item — so adding a page means updating: route → Livewire component → that nav array.

---

## 5. Tech stack

| Layer | Choice | Notes |
| --- | --- | --- |
| Language | PHP 8.2+ | `^8.2`; tested on 8.3 |
| Framework | Laravel 12.69.x | classic monolith, no API layer |
| UI framework | Livewire ~3.6 + Alpine.js | Livewire components drive all pages; Alpine handles POS cart, modals, toasts, dropdowns |
| Styling | Tailwind CSS 4.0 (Vite plugin) | custom coffee-green palette, `resources/css/app.css` |
| Fonts | `@fontsource-variable/bricolage-grotesque`, `instrument-sans` | display + body |
| Charts | Chart.js 4.5 | registered globally; `resources/js/app.js` sets palette |
| Server-rendered PDFs | barryvdh/laravel-dompdf 3.1 | 6 report blades under `resources/views/reports/` |
| Database | PostgreSQL (hosted on **Supabase**, session pooler port 5432) | `DB_CONNECTION=pgsql`, `DB_SSLMODE=require` |
| Sessions/cache/queue | `file` / `file` / `database` | keeps remote-DB round-trips low |
| Build | Vite 7 + laravel-vite-plugin | `npm run build` / `npm run dev` |
| Tests | PHPUnit 11 | **smoke tests only, database-free** by policy |
| Dev runner | `composer run dev` | concurrently: `serve` + `queue:listen` + `pail` + Vite |
| Time zone | `APP_TIMEZONE=Asia/Manila` | custom config path in `config/app.php` |
| Deployment target | any PHP host (e.g. MonsterASP) with `pdo_pgsql`; document root = `/public` | see SETUP.md |

Business helpers live in `app/Support/helpers.php` (autoloaded via composer `files`):
`peso()` formats pesos, `pct_change()` for dashboard deltas.

---

## 6. Architectural patterns

### 6.1 Service layer (the heart)

All business-critical mutations funnel through 6 services in `app/Services/`:

| Service | Responsibility | Invariants it protects |
| --- | --- | --- |
| `OrderService` | place, pay, markReady, complete, cancel, refund | price re-read from DB; number uniqueness via `pg_advisory_xact_lock(7301)`; cash paid only when drawer open; refund requires manager |
| `OrderPricing` | subtotal → VAT/discount math (static) | Philippine VAT rules, VAT-exempt discount stack |
| `CashShiftService` | open, movements, close | only one open shift (DB partial unique index + service check); no close with pending/ready orders; ignore negative denominations |
| `InventoryService` | stock_in, stock_out, adjust, closeUsage | `quantity_on_hand == Σ remaining batches`; FEFO ordering; one open unit per item; ADJ batches for corrections |
| `Analytics` | KPI & series queries (read-only) | consistent definition of revenue across dashboard/finance/reports |
| `ReportBuilder` | 6 report datasets for preview+PDF | same array feeds on-screen preview and exported PDF |
| `AuditLogger` | central log writer | rows immutable; every domain action gets a readable description |

Rule of thumb used everywhere: **components validate input → services enforce business rules →
`BusinessRuleException` bubbles up to `WithToasts::attempt()` and shows as an error toast.**

### 6.2 Livewire conventions

Every component:
- exposes filters via `#[Url]` attributes (shareable URLs).
- paginates with explicit `pageName` when two tables share a screen.
- uses the `WithToasts` + `ClearsErrorsOnInput` traits (toast errors clear the moment a user retypes).
- renders into `resources/views/livewire/<name>.blade.php`.
- calls services via method injection (`public function save(InventoryService $service)`).

### 6.3 Frontend shape

- POS cart is a **client-side Alpine store** preserved across refreshes (`localStorage`); only
  `checkout()` hits the server, which re-validates and re-prices everything.
- `x-confirm` modal (with into `$confirm()`) wraps destructive actions; toasts collect to
  `<x-toasts />` Alpine store; keyboard shortcuts `/`(search), `F9`(charge), `F4`(clear), `Esc`.
- Charts are plain `x-data="chart(@js($charts['sales']))"` Alpine components fed by the PHP class.

### 6.4 Reusable blades

Generic kit under `resources/views/components/`: `input`, `select`, `textarea`, `field`, `submit`,
`toggle`, `modal`, `confirm-dialog`, `toasts`, `page-header`, `empty-state`, `status-badge`,
`delta`, `search-input`, `icon`, `brand-mark`, `spinner`.

---

## 7. Database & ERD

### 7.1 migrations→tables (34 tables, ORDER of migration matters)

| Migration | Tables |
| --- | --- |
| `0001_01_01_000000_create_users_table` | users, password_reset_tokens, sessions |
| `0001_01_01_000001_create_cache_table` | cache, cache_locks |
| `0001_01_01_000002_create_jobs_table` | jobs, job_batches, failed_jobs |
| `0001_01_01_000100_create_settings_table` | settings |
| `000200_create_catalog_tables` | categories, products, product_variants, addons, addon_product, discounts |
| `000300_create_inventory_tables` | suppliers, item_categories, inventory_items, stock_ins, stock_batches, item_usages, stock_movements |
| `000400_create_sales_tables` | cash_shifts, cash_movements, orders, order_items, order_item_addons, payments, refunds |
| `000500_create_finance_tables` | expense_categories, expenses, incomes |
| `000600_create_audit_logs_table` | audit_logs |
| `000900_enable_row_level_security` | enables RLS on all 34 (Supabase PostgREST defense; no policies = app owner bypasses, anon REST is fully blocked) |

Laravel framework tables also present: users, sessions, cache, jobs.

### 7.2 Entity relationships (the important ones)

```
users 1──∞ orders (cashier)              users 1──∞ stock_ins (received_by)
users 1──∞ stock_movements               users 1──∞ payments (received_by)
users 1──∞ cash_shifts (opened_by, closed_by)
users 1──∞ item_usages (opened_by, closed_by)
users 1──∞ expenses (recorded_by) / incomes (recorded_by)
users 1──∞ refunds (processed_by, approved_by)

categories 1──∞ products ──∞ product_variants (cascade)   [product has ≥1 variant]
products ∞──∞ addons          via pivot addon_product
discounts 1──∞ orders (nullable, snapshot of name/amount)

suppliers 1──∞ inventory_items (default_supplier_id) and 1──∞ stock_ins and ∞──expenses
item_categories 1──∞ inventory_items
stock_ins 1──∞ stock_batches (cascade)              [a "delivery" header]
inventory_items 1──∞ stock_batches ──1 stock_ins (nullable = adjustment batch)
inventory_items 1──∞ item_usages (opened unit of the item, one 'in_use' max)
inventory_items 1──∞ stock_movements (append-only ledger)

cash_shifts 1──∞ orders / payments / refunds / cash_movements
orders 1──∞ order_items ──∞ order_item_addons   (snapshot names & prices)
orders 1──∞ payments (HasOne latestOfMany as ->payment) / 1──1 refunds

expense_categories 1──∞ expenses; expenses ∞──1 stock_in (optional, locks edit)
audit_logs: nullableMorphs('auditable') — subject row of any module
stock_movements: nullableMorphs('reference') — StockIn / ItemUsage origin
```

Rendered diagrams: [docs/erd/](erd/) — `erd_overview.svg`, `erd_products.svg`,
`erd_inventory.svg`, `erd_sales.svg`, `erd_finance.svg` (each has a `.png` twin).

### 7.3 Column-level notes that matter

- Money is `decimal(12,2)` everywhere; totals are **VAT-inclusive** ₱ amounts.
- Snapshots freeze history: `order_items.product_name/variant_name`, `order_item_addons.addon_name/price`,
  `orders.discount_name`, `audit_logs.user_name` — editing a product never rewrites past sales,
  user renames never rewrite past logs.
- Delete strategy: `restrictOnDelete` for reference data (users in invoices, items in movements,
  suppliers in stock_ins) but `nullOnDelete` for optional links; catalog children `cascadeOnDelete`
  from products only. Everything domain-facing is **soft deleted** (`deleted_at`), surfaced in Archive.
- Postgres-only `CHECK` constraints encode the hardest business rules (see §7.4).
- `item_usages` uniqueness trick: `CREATE UNIQUE INDEX item_usages_one_open_per_item
  ON item_usages (inventory_item_id) WHERE status='in_use' AND deleted_at IS NULL` — DB-level,
  race-proof enforcer of one-open-unit-per-item.
- `cash_shifts_one_open` partial unique index enforces a single open drawer shift.
- JSONB columns: `cash_shifts.denominations` (`{"1000":2,"500":3,…}`), audit `old_values`/`new_values`.
- `stock_batches` CHECK: `quantity_remaining BETWEEN 0 AND quantity_received` and `cost ≥ 0`.
- Uniqueness where it counts: partial unique indexes on `lower(name/sku/code/email/...)` among
  non-deleted rows allow re-creating the same name after archiving.

### 7.4 Hard constraints (CHECK constraints and indexes)

```
payments.method         CHECK methods cash|card|online
orders.type/status/payment_status PAYMENT CHECK: dine_in|takeout / pending|ready|completed|cancelled|refunded / unpaid|paid|refunded
discounts.type          CHECK percentage|fixed; value ≥ 0 (≤100 if percentage)
item_usages.status      CHECK in_use|depleted|discarded
item_usages open        UNIQUE per item while status='in_use' (partial index)
stock_movements.type    CHECK stock_in|stock_out|adjustment_in|adjustment_out|waste|expired
cash_movements.type     CHECK cash_in|cash_out; amount > 0
cash_shifts.status      CHECK open|closed; only one open (partial unique)
inventory.qty           CHECK quantity_on_hand ≥ 0
product_variants.price  CHECK ≥ 0; addons/total etc. non-negative
expenses/incomes        amount > 0; unique lower(name) per category type
```

### 7.5 Security posture

- Row Level Security is **enabled with no policies** — because the app connects as the table
  owner, it bypasses RLS, but Supabase's PostgREST anon key cannot touch any table.
- Bcrypt(12) rounds for passwords & manager PINs; PIN never written in audit (only `••••••`).
- Rate limiting on login (5/min) and refund PIN (5 per 5 min); `login_failed` / `refund_denied` audited.
- Trusted proxies `at: '*'` (so IP + HTTPS work behind standard hosts); CSRF everywhere; sessions regenerated on login.

---

## 8. Object–relational mapping reference

Namespace: `App\Models\` · Table names follow Laravel plural conventions.

| Model | Table | Key relationships (`withTrashed()` on many) | Soft deletes | Auditable trait | Notes |
| --- | --- | --- | --- | --- | --- |
| `User` | users | hasMany orders | ✅ | ✅ `users` | `role`, hashed `pin`, case-insensitive email/username set in mutators, `checkPin()`, `initials()` |
| `Setting` | settings | — | ❌ | ✅ `settings` | static `get`/`bool`/`put` cached 10 min, `DEFAULTS` incl. `store_name`, `vat_registered`, `vat_rate` |
| `Category` | categories | hasMany products (ordered) | ✅ | ✅ `products` | icon + `sort_order`, partial-unique lower(name) |
| `Product` | products | belongsTo category; hasMany variants, addons (pivot `addon_product`), order_items | ✅ | ✅ | `is_available` toggle, `image_path` on `uploads` disk, `imageUrl()` |
| `ProductVariant` | product_variants | belongsTo product; used in order_items | ✅ | ✅ | price decimal, `is_default`, `sort_order` |
| `Addon` | addons | belongsToMany products; used in order_item_addons | ✅ | ✅ | active toggle |
| `Discount` | discounts | hasMany orders (snapshot only) | ✅ | ✅ | `type` percent/fixed, `is_vat_exempt`, `requires_id`, `label()` |
| `Supplier` | suppliers | hasMany stock_ins, inventory_items(default) | ✅ | ✅ `inventory` | validated phone pattern |
| `ItemCategory` | item_categories | hasMany inventory_items | ✅ | ✅ `inventory` | separate from menu categories |
| `InventoryItem` | inventory_items | belongsTo category/supplier; hasMany batches, usages, movements; `currentUsage()` | ✅ | ✅ | `quantity_on_hand` deliberately NOT fillable; `availableBatches()` FEFO scope; `isLowStock()`, `stockStatus()` |
| `StockIn` | stock_ins | belongsTo supplier, receiver(user); hasMany batches; `expense()` | ✅ | ✅ | `reference_no` SI-YYYYMMDD-####, total_cost |
| `StockBatch` | stock_batches | belongsTo item + stock_in (nullable) | ✅ | ✅ | qty_received/remaining, unit_cost, expiry |
| `ItemUsage` | item_usages | belongsTo item, batch, opener/closer | ✅ | ✅ | statuses `in_use|depleted|discarded`; `STATUS_*` constants; `durationLabel()` |
| `StockMovement` | stock_movements | belongsTo item, batch, user; morphTo reference | ❌ append-only | — | signed qty, balance_after, unit_cost audit trail |
| `CashShift` | cash_shifts | belongsTo opener/closer; hasMany orders, payments, refunds, cashMovements | ✅ | ✅ `sales` | `current()` finds open; `computeExpectedCash()`; `summary()` breakdown; `denominations` JSON |
| `CashMovement` | cash_movements | belongsTo shift, user | ❌ | — | petty cash in/out during a shift |
| `Order` | orders | belongsTo cashier/shift/discount (all withTrashed); hasMany items, payments; hasOne payment (latestOfMany); hasOne refund | ✅ | — (explicitly logged) | status constants + `canMarkReady/canComplete/canCancel/canRefund`; `activeQueue()` scope |
| `OrderItem` | order_items | belongsTo order, product, variant; hasMany addons (as order_item_addons) | ❌ (no soft delete) | — | price/qty snapshots, line_total |
| `OrderItemAddon` | order_item_addons | belongsTo order_item, addon | ❌ | — | addon snapshot |
| `Payment` | payments | belongsTo order, receiver; shift (nullable) | ❌ | — | METHODS cash/card/online; PROVIDERS list; tendered vs change for cash |
| `Refund` | refunds | belongsTo order, payment, processor, approver; shift (nullable) | ❌ | — | who processed it at POS + who approved via PIN |
| `ExpenseCategory` | expense_categories | hasMany expenses | ✅ | ✅ `finance` | `inventoryPurchases()` finds the special system category |
| `Expense` | expenses | belongsTo category, supplier, stockIn, recorder | ✅ | ✅ `finance` | payment_methods constant; locked from edit when it came from a stock delivery |
| `Income` | incomes | belongsTo recorder | ✅ | ✅ `finance` | non-sales income |
| `AuditLog` | audit_logs | belongsTo user (withTrashed); morphTo auditable | ❌ immutable | — | `MODULES` list; `tone()` colour mapping |

**Morph map**, enforced in `AppServiceProvider::boot()` (`Relation::enforceMorphMap`), so
polymorphic columns store short stable names instead of class paths:

```
user · setting · category · product · product_variant · addon · discount · supplier ·
item_category · inventory_item · stock_in · stock_batch · item_usage · stock_movement ·
cash_shift · cash_movement · order · order_item · payment · refund · expense_category ·
expense · income · audit_log
```

The map is a superset of what's actually morphed — it exists to keep names readable and future-proof.

---

## 9. Data computed, not stored

Know these to avoid re-implementing or duplicating them:

| Derived measure | Where |
| --- | --- |
| Expected cash / drawer variance | `CashShift::computeExpectedCash()` (used live and at close) |
| Order totals (VAT, VAT-exempt, discount) | `OrderPricing::compute()` at order placement; stored finalized |
| Best-seller / cashier leaderboard / category revenue | `Analytics::topProducts/cashierPerformance/salesByCategory()` — grouped from `order_items` |
| Inventory stock value | Σ remaining batch qty × unit cost (Reports) |
| Refund eligibility | `Order::canRefund()` — paid and not already refunded |
| System category "Inventory Purchases" | `ExpenseCategory::inventoryPurchases()` — a `is_system=true` row; stock-in expenses tie to it |

Definitions used consistently:
- **Net sales** = Σ total_amount of paid orders (VAT-inclusive; refunds excluded because their
  payment_status flips to `refunded`).
- **Transactions** = count of paid orders; **avg. ticket** = net sales / transactions.
- **Net income** = net sales + other income − expenses.

---

## 10. Operational knowledge worth knowing

### 10.1 Seeders

`php artisan migrate:fresh --seed` runs: `Settings` → `Users` → `Catalog` →
`InventoryMaster` → `FinanceMaster` seeders, then (env toggle `SEED_DEMO_DATA=true`, default)
`DemoHistorySeeder` generating 90 days of realistic sales/stock/finance history
(`SEED_DEMO_DAYS`, `SEED_INVENTORY_DAYS` control how much).

| Role | Username | Password | Refund PIN |
| --- | --- | --- | --- |
| Manager | `manager` | `Manager@123` | 1234 |
| Manager | `manager2` | `Manager@123` | 5678 |
| Staff | `staff` | `Staff@123` | — |
| Staff | `carlo`, `jasmine` | `Staff@123` | — |

**Change these before real use** (Users → reset password; My profile → change PIN).

### 10.2 Demo walkthrough (the SETUP.md "typical first demo")

1. Sign in as `staff` → open the cash drawer.
2. Take a POS order (try a Senior discount to see the VAT-exempt math), charge in cash.
3. In Order queue: mark ready → hand over → receipt.
4. Stock on hand: stock out a carton → mark depleted → stock out again.
5. Sign in as `manager`: see it all on Dashboard/Reports/Audit logs.

### 10.3 Testing policy (⚠️ read before touching tests)

`php artisan test` runs **database-free smoke tests only. `refreshDatabase`/`RefreshDatabase` would
wipe the connected (remote Supabase) database — that's a documented warning, not a suggestion.**
Run any DB-touching test against a *separate* throw-away database only.

### 10.4 Known deliberate simplifications

- One shared Senior/PWD discount per order → ring seniors on their own order when splitting bills.
- Sales don't decrement inventory (open-container model hand-managed by staff).
- Refunds are full-order; there's no partial line refund.
- `discount_id_number` captured but not validated against a government registry.
- Reports cap at most 1500 audit rows per export for size.

### 10.5 Gotchas that will bite you if ignored

- **Advice-lock numbering**: order/stock-in numbering relies on `pg_advisory_xact_lock(7301/7302)`.
  Postgres-only; renaming the constants is fine, running on SQLite won't collide the same way.
- **Never bulk-edit `quantity_on_hand`** — it must change only through `InventoryService` or the
  batch/ledger sync breaks.
- **`quantity_on_hand` is not fillable**, so mass assignment can't smuggle stock edits.
- **Receipt always re-renders live** — a refund flips the receipt page to show a REFUNDED stamp.
- **`stock_in_id`-linked expenses are locked** — archived stock-in-linked expenses can't be
  archived from Finance, restoring the delivery's cost requires its source stock-in.
- **Archive/restore rules in `Archive::restore`**: a child cannot be restored while its parent
  category is still archived, and a name conflict with a live row blocks restore until resolved.
- **Archiving products keeps past orders readable** (snapshot names survive).

### 10.6 Where to look when debugging

| Symptom | File to open |
| --- | --- |
| Wrong order totals / VAT math | `app/Services/OrderPricing.php` |
| Cart / checkout weirdness | `app/Livewire/Sales/Pos.php` + `resources/js/pos.*` |
| Queue transitions blocked | `app/Services/OrderService.php` (Business message tells why) |
| Drawer over/short confusion | `app/Models/CashShift::computeExpectedCash` + `CashShiftService::close` |
| Stock count off | `InventoryService` invariant; run the SQL in TESTING.md data-integrity table |
| Number reuse (`ORD-.../SI-...`) | `OrderService::nextNumbers` / `InventoryService::nextStockInReference` |
| Nav/permission issues | `app/Http/Middleware/EnsureUserHasRole.php`, `bootstrap/app.php` middleware aliases |
| Anything missing from audit log | `app/Services/AuditLogger.php` + `app/Models/Concerns/Auditable.php` |

---

## 11. Extending the system (quick recipes)

- **New page**: add route in `routes/web.php` (right middleware block) → create Livewire class →
  blade view → add entry to the nav array in `components/layouts/app.blade.php`.
- **New audit event**: call `AuditLogger::log(module, event, description, subject, old, new)` —
  add the event's tone to `AuditLog::tone()` for consistent badges.
- **New report**: add entry to `ReportBuilder::TYPES` + private `build()` method + blade in
  `resources/views/reports/` + wire into `Reports\Index` + `ReportExportController`.
- **New payment provider**: extend `Payment::PROVIDERS` (no schema change).
- **New check constraint or invariant**: prefer adding both a Livewire validation *and* a Postgres
  CHECK constraint so the DB stays safe even under race conditions.

---

*Guide generated October 2026 from full source inspection (Laravel 12.69.3 / Livewire 3.8.10).*
