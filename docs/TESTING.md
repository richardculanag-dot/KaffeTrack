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
