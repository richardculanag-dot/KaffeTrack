# Sales ↔ Inventory integration

HEIM keeps its open-container method: staff **stock out** one unit (a milk carton, a bag of beans), use it,
and **mark it depleted** when it is used up. KaffeTrack connects that method to sales **without estimates
and without interrupting service**:

- During the shift, staff only take orders. Recording stock-outs is optional and never prompted.
- When the cash drawer is closed, the system lists the items that shift's sales used, staff count the sealed
  units left, and the difference is recorded as real stock-outs (or waste).

Nothing is guessed: the system compares two sets of records (what was sold, what is in storage), and a person
confirms what physically happened. It never deducts recipe amounts per drink (HEIM has no measured
recipes), never blocks a sale and never changes stock on its own.

## How it works

1. **Products list what they use** (Products → edit → "Uses from inventory"). Example: Café Latte uses
   *House Blend Beans* (role `espresso`) and *Fresh Milk* (role `milk`). Items only, no amounts.
2. **Add-ons can change it** (Categories & add-ons → edit → "Effect on inventory"):
   - *Replace* a role: **Oat milk** replaces `milk`, so an oat latte uses Oat Milk instead of Fresh Milk.
   - *Add* an item: **Caramel drizzle** also uses Caramel Sauce.
3. **Every sold line remembers which items it used**, fixed when the order is placed (like the price), so
   later menu edits never change history.
4. **During the shift** nothing about inventory appears in the order flow. Staff may still use Stock on
   hand: **Stock out** asks how many units were taken out (default 1, or e.g. 3 when recording later) and
   whether the last one is in use or already used up. Units are opened one after another (only one is open
   per item), so an open unit and all but the last new one are marked used up.
5. **At the end of the shift** (Cash drawer → End-of-day count), step 1 is the **stock check**:
   - It lists every item used by orders made in this shift (marked Ready at some point; an order cancelled
     while pending never used anything) that hasn't been counted yet — **or that was sold again after its
     last count**. If staff count, keep the shift open and sell more, those items are listed again at the
     next End-of-day count; counting again never records anything twice, because each count is compared
     with the system's current number.
   - For each item, staff count the **sealed** units left on the shelf (not the open one) and say whether
     the unit open at the bar *still has some* or is *empty*. Optional: how many were thrown away, and a note.
   - The count is **blind**: the system's number is shown only on the review screen, so staff count what is
     really there instead of confirming a number.
   - **"Used something without an order?"** adds any other item to the count (a spill replacement, cups
     for staff drinks), so stock used in the background is caught at the same moment. If the shift had no
     orders at all, there is no stock check step — record anything used in Stock on hand → Stock out.
   - **Review** shows what will be recorded before anything is saved. If staff say a unit is open but none
     was ever stocked out and none is missing, it warns them to recount; if they keep the answer, the
     manager gets a note.
6. **What gets recorded** (all in the stock ledger, under the staff member's name, labelled
   "end-of-shift check (shift #N)"):

   | Shelf count vs system | Recorded |
   |---|---|
   | Same | Nothing (the open unit stays open, or is marked finished if "empty") |
   | Fewer by *n* | Expired units come off first and are recorded as **expired** (written off, never as used in drinks); then any thrown away (waste); the rest are stock-outs. Units are opened one after another, so all but the last are marked finished; "still has some / empty" decides the last one. |
   | More | Nothing changes. Noted as "more than recorded — check for a delivery that was not entered" for the manager. |

7. **Step 2 is the cash count**, as before. The drawer cannot be closed until the stock check is done.

Example: 15 sealed cartons of Fresh Milk at the start. During a rush 3 cartons are opened without
recording anything. At closing staff count 12 and tap *Still has some* → 3 stock-outs recorded, the first
2 marked finished, the third stays open for the next shift.

## Where to find it

- **Products**: "Uses from inventory" in the product form.
- **Categories & add-ons**: "Effect on inventory" in the add-on form.
- **Cash drawer**: End-of-day count → Step 1 Stock check → Step 2 Cash count. While the shift is open, its
  card shows "Stock check: N counts, last at … — View"; *Details* on a closed shift shows every count.
- **POS**: a drink whose item has nothing in storage and nothing open shows "*Item*: none in stock — check
  before confirming". A label only; it never blocks the sale.
- **Stock movements**: stock-outs and waste from the check, with the shift number in the reason.
- **Reports → Inventory**: "End-of-shift stock checks" for shifts opened in the period (how many counts
  matched, and every difference or note).
- **Reports → Financial**: "Sales vs cost of stock used": net sales next to the purchase cost of units
  opened and thrown away in the period, per item and as a percentage of sales.
- **Reports → Cash drawer**: each shift's stock-check result under its cash count.

## Data model

| Table | Purpose |
|---|---|
| `product_ingredients` | product → inventory item, with a role (e.g. `milk`) so an add-on can replace it |
| `addon_ingredient_rules` | add-on → add an item, or replace the item in a role |
| `order_item_ingredients` | which items each sold line used (snapshot at order time) |
| `shift_stock_checks` | one line per item per shift: sealed units the system had, sealed units counted, stocked out, wasted, whether the open unit was finished, who checked and when |

Stock quantities change only through the existing ledger (`stock_movements`, `item_usages`, `stock_batches`),
so `inventory_items.quantity_on_hand = Σ stock_batches.quantity_remaining` still holds.

## Why not deduct stock automatically per order?

That needs an exact amount per drink ("18 g of beans per latte"). HEIM does not measure recipes, so the
system would subtract guesses and the stock numbers would drift from the shelf. KaffeTrack keeps stock
controlled by physical actions (deliveries, opening a unit, a shelf count) and uses sales to tell staff
**what to check**.

## Defending it in one sentence

*KaffeTrack links products to the inventory items they use; at the end of each shift it compares what was
sold with what was recorded and asks staff to confirm the physical count, so the inventory ledger stays
consistent with sales without interrupting service or relying on estimates.*
