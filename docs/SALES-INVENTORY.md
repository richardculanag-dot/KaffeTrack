# Sales ↔ Inventory integration

HEIM keeps its open-container method: staff **stock out** one unit (a milk carton, a bag of beans), use it,
and **mark it depleted** when it is used up. KaffeTrack now connects that method to the POS so it can warn
*before* an open unit runs out. It does **not** deduct exact ingredient amounts per drink — HEIM has no
standardised recipes.

## How it works

1. **Products list what they use** (Products → edit). Example: Café Latte uses *Fresh Milk* (role `milk`)
   and *House Blend Beans* (role `espresso`). No amounts — only which items.
2. **Add-ons can change it** (Categories & add-ons → edit):
   - *Replace* a role — **Oat milk** replaces `milk`, so an oat latte counts against the oat milk carton.
   - *Add* an item — **Extra espresso shot** adds 1 serving of beans.
3. **When a drink is marked Ready**, it is counted once against the unit of each item that is open at that
   moment. Orders cancelled or refunded **before** Ready are never counted; after Ready they stay counted
   (the ingredient was really used).
4. **Each open unit is compared with what a unit usually serves** — the average of the last 5 units used up
   (needs at least 3), or the manager's starting estimate until then.
5. **Alerts** (each fires once per unit):

   | Stage | When | Who sees it |
   |---|---|---|
   | Alert | e.g. 80 % of the usual count (Settings, or per item) | Toast for the barista, bell for everyone (names the shift-in-charge) |
   | Reached | 100 % | Same, until the unit is marked depleted |
   | Review | 150 % — probably a unit was opened without recording it | Managers only; resolved with a reason in Stock on hand |

6. **Staff decide**: *I checked — still enough*, open another unit, or mark depleted. The system never
   closes a unit, never blocks a sale and never changes stock quantities.
7. **No open unit?** Marking Ready shows *Open it now & mark ready* (one tap records the stock-out) or
   *Mark ready anyway* (the drink is recorded as "no open unit" and shown in the bell).

## What is an estimate and what is a record

| Record (exact) | Estimate (for planning) |
|---|---|
| Units in storage, stock-ins, stock-outs, write-offs (stock ledger) | Drinks served per open unit, alerts, cost per drink |

Physical counts stay the final check. A unit flagged for review is usually explained by comparing the storage
count with the system: one carton fewer than expected = a carton was opened without a stock-out.

## Where to find it

- **Products** — "Uses from inventory" in the product form; the list shows "Uses …".
- **Categories & add-ons** — "Effect on inventory" in the add-on form.
- **Stock on hand** — meter on each open unit; item form → "Estimate drinks served per open unit";
  item history → "Usually serves ~N"; ⋯ → *Review over-usage…* (manager).
- **Order queue** — Ready prompt and estimate toasts.
- **POS** — soft warning on a product whose item shows none in storage and none open (never blocks).
- **Settings** — default alert level.
- **Reports → Inventory** — "Drinks served per opened unit": units opened, drinks counted, average drinks per
  unit, approximate cost per drink, alerts and reviews.

## Data model

| Table | Purpose |
|---|---|
| `product_ingredients` | product → inventory item, with a role and servings per drink (default 1) |
| `addon_ingredient_rules` | add-on → add an item or replace a role |
| `order_item_ingredients` | what each sold line used (snapshot at order time) and which open unit it was counted against |
| `inventory_items` + `tracks_servings`, `expected_servings`, `servings_alert_percent` | per-item settings |
| `item_usages` + `servings_used`, `expected_servings`, `alert_percent`, `alerted_at`, `reached_at`, `flagged_at`, acknowledgement and review fields, `exclude_from_history` | the running estimate for one open unit |

Code: `App\Services\RecipeResolver` (which items a drink uses) and `App\Services\ServingTracker`
(counting, alerts, reviews). The demo links are in `Database\Seeders\SalesInventoryLinkSeeder` — the
"usually serves" numbers there are starting guesses, not HEIM's real figures.

## Known limits

- All sizes count as one drink unless a product's servings are changed (e.g. 1.5).
- Staff drinks, remakes and spills are not recorded, so the usual count already includes them.
- Only one unit of an item can be open at a time (existing rule); coffee-cart service is not separated.
