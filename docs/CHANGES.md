# Changes after the initial build

## Update 3 — Simpler Sales ↔ Inventory link: end-of-shift stock check

**Why:** the Update 1 integration estimated "drinks per container" and interrupted staff during service
(Ready prompt, toasts, alerts). During a rush that adds work at the worst moment, and the estimates were
hard to defend. Update 3 keeps the link between sales and inventory but moves the human part to the end
of the shift and removes every estimate. See `docs/SALES-INVENTORY.md`.

**Database:** one new migration, `2026_10_13_000100_end_of_shift_stock_check`. It adds the
`shift_stock_checks` table and **removes** the estimate columns added in Update 1 (drinks served, expected
servings, alert levels, review fields, servings per ingredient). Run `php artisan migrate`. Estimate
history is demo data and is not kept; stock quantities and the stock ledger are not touched.

**New**
- Cash drawer → End-of-day count is now two steps: **Stock check** (count sealed units left for every item
  the shift's sales used; review before saving) and **Cash count**. The drawer can't close until the check
  is done. Differences are recorded as stock-outs or waste in the ledger.
- Closed shift details, the Cash drawer report and the Inventory report show the stock checks.
- Financial report: **Sales vs cost of stock used** (net sales next to the purchase cost of units opened
  and thrown away).
- Stock out while a unit is open now marks the open one used up (one tap instead of two).

**Removed**
- "No open unit" prompt when marking Ready; servings toasts; estimate alerts in the bell.
- Usage meter, "I checked — still enough", "Review over-usage" and the estimate fields in Stock on hand.
- "Servings" on product ingredients and add-on rules; "Inventory estimates" in Settings;
  "Drinks served per opened unit" in the Inventory report.

**Kept:** product–ingredient links, add-on rules, the record of which items each sale used, and the POS
"none in stock" label (now worded as a fact).

**Demo data:** `php artisan db:seed --class=SalesInventoryLinkSeeder` (safe to re-run) also records which
items the last 7 days of demo orders used and adds matched stock checks for the last 7 days of demo
shifts, so the reports have history.

## Update 2 — Validation and safety pass (pre-defense inspection)

**Database:** one new migration, `2026_10_12_000100_tighten_sales_rules` (a CHECK constraint and two
unique indexes; no tables or columns added or removed). Run `php artisan migrate`.

**Names and text fields** — the same rule everywhere a name is typed
- Names must contain a letter and use only letters, numbers, spaces and `- & ' . , ( ) / + % # ₱ !`
  (`app/Rules/DisplayName.php`). Applies to products, sizes, menu and item categories, add-ons,
  discounts, inventory items and units, suppliers and contact persons, expense categories, user names,
  ingredient roles and the POS customer name.
- Names are saved trimmed with single spaces (`app/Models/Concerns/NormalisesName.php`).
- Duplicates are checked ignoring letter case and extra spaces, among records that are not archived
  (`app/Rules/UniqueName.php`): "Café Latte" and " café  latte" are the same name. This also covers
  product codes, SKUs, usernames and emails.
- Phone numbers: digits, spaces, `+ ( ) -`, 7–30 characters. Product codes and SKUs: letters, numbers,
  `- _ .`. Senior/PWD ID numbers: letters, numbers, spaces and dashes.

**Sales**
- A fully discounted (₱0.00) order can now be paid; it can't be refunded (nothing to return).
- One payment and one refund per order, enforced by the database; two people clicking at once can no
  longer record it twice. Opening the drawer at the same moment from two devices shows a message
  instead of an error page.
- Cash received is capped at ₱1,000,000 in the order queue (as it already was at the POS).

**Inventory**
- Stock in: expiry dates must be today or later; delivery dates up to 30 days back; one delivery may
  total at most ₱10,000,000.
- Archive → Restore also checks SKUs, product codes, usernames and emails (ignoring case), not only names.

**Accounts and security**
- Role and active-account checks now run on every button press, not only when a page is opened. A
  manager demoted to staff, or a deactivated user, is stopped on their next action.
- Promoting a staff member to manager requires a refund PIN.
- Sign-in throttling can't be bypassed by adding spaces or changing letter case; the "account
  deactivated" message only appears after the correct password.
- Wrong refund PINs are limited per manager (5 tries, then 5 minutes), not per browser session.
- Record IDs used by edit/payment/refund dialogs can't be changed from the browser (`#[Locked]`); a
  tampered request gets 403.
- Settings only saves the settings shown on the screen.
- Typing an invalid date, month or filter in the address bar shows the normal page instead of an error.
- Expense and income dates must be from 2020 onward.

**Decided against:** permanent delete. Archive stays the only way to remove records, so sales history,
reports and the audit trail always keep their references.

## Update 1 — Sales ↔ Inventory integration, table picker, deployment

**Database:** one new migration, `2026_10_11_000100_link_sales_to_inventory` (adds 3 tables and columns to
`inventory_items` and `item_usages`; nothing is renamed or removed). Run `php artisan migrate`.

**New**
- Sales ↔ Inventory integration (its estimate features were replaced in Update 3).
- Dine-in table picker (buttons 1…N) with the number of tables in Admin → Settings; validated on the server.
- Settings: number of tables, default estimate alert level (the alert level was removed in Update 3).
- Inventory report: "Drinks served per opened unit" section (replaced in Update 3).
- Deployment to Render: `Dockerfile`, `docker/`, `.dockerignore`, `render.yaml`, `.gitattributes`
  (see `docs/SETUP.md` → Deploying to Render).

**Changed**
- Order details no longer show "Refunded" twice.
- `config/livewire.php` also sets the Livewire 4 layout key (harmless on Livewire 3).
- `.env.example` defaults to a local PostgreSQL database; README rewritten for teammates.

**Demo data:** `php artisan db:seed --class=SalesInventoryLinkSeeder` links the demo menu to the demo
inventory. It is safe to run more than once and on an already-seeded database.
