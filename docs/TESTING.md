# KaffeTrack — test record

Environment: PHP 8.3.6, Laravel 12.69.3, Livewire 3.8.10, PostgreSQL 16 (same engine as Supabase),
Chromium (Playwright) at 1440 × 900 and 390 × 844. Date: October 10, 2026.
All cases below were executed end-to-end in a real browser against a freshly migrated and seeded database;
results were confirmed both on screen and with SQL queries.

## Functional tests

| ID | Module | Test case | Expected result | Result |
| --- | --- | --- | --- | --- |
| A-01 | Auth | Sign in with username in capitals (`MANAGER`) | Signs in (case-insensitive) and lands on Dashboard | Pass |
| A-02 | Auth | Staff signs in | Lands on POS; sidebar shows only staff pages | Pass |
| A-03 | Auth | Deactivated account signs in | "This account is deactivated…" | Pass |
| A-04 | Auth | Six wrong passwords | Locked for ~60 s; each failure written to audit logs | Pass |
| A-05 | Auth | Staff opens 11 manager-only URLs (incl. PDF export) | 403 page for each | Pass |
| P-01 | POS | Sell with no open shift | POS asks to open the drawer first | Pass |
| P-02 | POS | Add single-size item twice | One line, quantity 2 | Pass |
| P-03 | POS | Customise drink (size, add-on, note) | Line shows size, add-on and note; price includes add-on | Pass |
| P-04 | POS | Senior discount without ID | Charge blocked with message | Pass |
| P-05 | POS | Senior discount on ₱390 | Less VAT ₱41.79, discount ₱69.64, total ₱278.57 (UI = server) | Pass |
| P-06 | POS | Cash ₱500 | Change ₱221.43 on success screen and in DB | Pass |
| P-07 | POS | Save as unpaid | Order in queue marked Unpaid | Pass |
| P-08 | POS | Receipt | 80 mm receipt with VAT breakdown and Senior ID | Pass |
| Q-01 | Queue | Mark ready → hand over (paid) | Status completed; timestamps set | Pass |
| Q-02 | Queue | Hand over unpaid order | Opens Collect payment instead | Pass |
| Q-03 | Queue | Online payment without reference | Validation error | Pass |
| Q-04 | Queue | Refund with wrong manager PIN | Rejected; `refund_denied` audit entry | Pass |
| Q-05 | Queue | Refund with correct PIN | Order refunded; refund row names approving manager | Pass |
| C-01 | Drawer | Close with ₱200 short and no note | Blocked: explanation required | Pass |
| C-02 | Drawer | Expected cash | ₱2,500 opening + ₱160 cash sale − ₱160 cash refund = ₱2,500 | Pass |
| I-01 | Inventory | Stock out while a unit is open | Blocked (button replaced by Mark depleted; DB unique index) | Pass |
| I-02 | Inventory | Mark depleted → stock out | New unit in use; quantity −1; ledger row | Pass |
| I-03 | Inventory | Waste write-off of 2 | Quantity −2 from earliest-expiring batch; ledger + audit | Pass |
| I-04 | Inventory | Delivery missing expiry dates | Inline "Required for this item." errors | Pass |
| I-05 | Inventory | Delivery of 2 lines | 2 batches; stock up; ₱4,500 Inventory Purchases expense created | Pass |
| R-01 | Products | Save empty product form | 4 inline errors | Pass |
| R-02 | Products | Create product with 2 sizes, add-on, photo | Saved; photo stored in `public/uploads/products` | Pass |
| R-03 | Products | Change a size price | Audit log stores before/after prices | Pass |
| R-04 | Products | Archive then restore from Archive | Hidden, then back | Pass |
| R-05 | Discounts | 150 % discount | "A percentage cannot be more than 100." | Pass |
| R-06 | Suppliers | Invalid phone; name with apostrophe | Validation error; dialog title "Archive Kevin's Bakery?" renders correctly | Pass |
| F-01 | Finance | Record expense ₱1,250.50 | Listed and totalled | Pass |
| F-02 | Finance | Edit an expense created by a delivery | No edit/archive controls (locked) | Pass |
| U-01 | Users | Duplicate username, short password | Both errors shown | Pass |
| U-02 | Users | New manager without PIN | "Managers need a PIN to approve refunds." | Pass |
| S-01 | Settings | Change receipt footer | Saved; one summarised audit entry | Pass |
| M-01 | Profile | Wrong current password | "Your current password is incorrect." | Pass |
| X-01 | Reports | Export all 6 PDF reports | All generated (0.2–1 s), 30–170 KB, ₱ renders | Pass |

## Data integrity checks (SQL, after seeding and after the tests)

| Check | Result |
| --- | --- |
| `inventory_items.quantity_on_hand` = Σ `stock_batches.quantity_remaining` for every item | 0 violations |
| `quantity_on_hand` = Σ `stock_movements.quantity` for every item | 0 violations |
| Every order's subtotal = Σ its line totals | 0 mismatches |
| Every paid/refunded order has a payment | 0 missing |
| Every shift's expected cash = opening + cash sales − cash refunds − cash out | 0 mismatches |
| Every delivery's total = its linked expense | 0 mismatches |
| Row Level Security enabled on every table | 34 / 34 |

## Page smoke test

All 23 pages load with HTTP 200 for the manager and the six staff pages for staff, with no server
errors and no browser console errors. At 390 px width no page scrolls horizontally.

## Hand-checked calculations

- Income statement (Sept 2026): gross ₱329,530.00 − ₱6,934.72 = net sales ₱322,595.28; + ₱15,600 other
  income = ₱338,195.28; expenses ₱261,013.00; net income ₱77,182.28 (22.8 %).

## Sales ↔ Inventory integration (end-of-shift stock check, Update 3)

| # | Steps | Expected |
|---|---|---|
| SI-1 | Products → edit Café Latte | "Uses from inventory" lists espresso → House Blend Beans, milk → Fresh Milk (no amounts) |
| SI-2 | Add an empty ingredient row → Save | Errors: role and item required; nothing saved |
| SI-3 | Sell Café Latte, mark Ready in the queue | No inventory prompt or toast; order goes to Ready |
| SI-4 | Stock on hand → Fresh Milk → Stock out twice | Second toast: "The previous one was marked as used up"; only one unit open |
| SI-5 | Cash drawer → End-of-day count | Step 1 Stock check lists Beans, Fresh Milk (and Oat Milk if an oat latte was sold) |
| SI-6 | Click Review counts with nothing filled | Every item asks for a count and the open-unit answer; nothing saved |
| SI-7 | Fresh Milk: count 2 fewer than the system, "Still has some" → Review | "2 stocked out (1 finished) · open carton stays open" |
| SI-8 | Oat Milk: count 1 fewer, thrown away 1, "Empty / none" → Review | "1 thrown away · open carton marked finished" |
| SI-9 | Any item: count more than the system | "More than recorded — check for a delivery not entered; stock unchanged" |
| SI-10 | Record stock check | Toast with totals; Stock movements show the stock-outs/waste with "end-of-shift check (shift #N)"; Step 2 Cash count opens |
| SI-11 | Try to close the drawer before the check (another device) | "Finish the end-of-shift stock check first" |
| SI-12 | Cancel an unpaid order while still pending | Its items are not listed in the check |
| SI-13 | Closed shift → Details | "End-of-shift stock check" table with system/counted/recorded |
| SI-14 | Reports → Inventory PDF | "End-of-shift stock checks": matched count and every difference |
| SI-15 | Reports → Financial PDF | "Sales vs cost of stock used": net sales, cost of units opened and thrown away, % of sales |
| SI-16 | POS: a drink whose item has 0 in storage and none open | "*Item*: none in stock — check before confirming"; the sale still goes through |
| SI-17 | Stock on hand → Stock out → quantity 3, "In use now" | Storage −3; three ledger lines; the first two (and any unit open before) marked used up, the last open |
| SI-18 | Stock out → quantity more than storage | "Only N in storage"; nothing recorded |
| SI-19 | Stock out → 1, "Already used up" | Storage −1; nothing left open |
| SI-20 | Stock check → "Used something without an order?" → add 16 oz Cold Cups, count 2 fewer | Listed with "Added"; review "2 stocked out"; recorded |
| SI-21 | Stock check: item with no open unit, count matches, "Still has some" | Review warns to recount; after saving, the check has a manager note |
| SI-22 | Cash count step → "Count it first" with an item | Returns to Step 1 with that item |

## Update 2 — validation and safety

| # | Steps | Expected |
|---|---|---|
| V-1 | Categories & add-ons → Add category "  espresso   CLASSICS " | "already used by another record"; nothing saved |
| V-2 | Add category "12345" | "must include letters" |
| V-3 | Stock on hand → Add item named "oat milk", SKU "dy-oat", unit "-1" | Errors on name, SKU and unit |
| V-4 | Suppliers → Add an existing supplier name in capitals, contact person "999" | Errors on both |
| V-5 | Users → Add username "MANAGER2" | "already used" |
| V-6 | Users → Edit a staff member, set role to Manager, leave PIN empty | "Managers need a PIN" |
| V-7 | POS: 100 % discount order, pay cash ₱0 | Order paid; refund says there is nothing to refund |
| V-8 | Stock in: expiry yesterday; date 45 days ago | Both rejected with messages |
| V-9 | Stock in: qty 10,000 × ₱999,999 | "over the ₱10,000,000.00 limit" under the total |
| V-10 | Manager A on Users; demote A in another session; A clicks Add user | 403, nothing opens |
| V-11 | Staff on Order queue; deactivate them; they click anything | Sent to the login page |
| V-12 | Login 6× with wrong password using "carlo", " CARLO", "Carlo " … | 6th attempt: "Too many sign-in attempts" |
| V-13 | Deactivated user, wrong password / right password | Generic error / "account is deactivated" |
| V-14 | Open `/orders?date=xx`, `/finance?month=abc`, `/reports?preset=custom&from=nope`, `/archive?source=x` | Normal page, no error |
| V-15 | Browser console on Order queue: `$wire.set('payingId', 1)` | 403 |
| V-16 | Archive an item, add a new item with its SKU, restore the archived one | "already uses the same SKU" |
