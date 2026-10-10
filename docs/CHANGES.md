# Changes after the initial build

## Update 1 — Sales ↔ Inventory integration, table picker, deployment

**Database:** one new migration, `2026_10_11_000100_link_sales_to_inventory` (adds 3 tables and columns to
`inventory_items` and `item_usages`; nothing is renamed or removed). Run `php artisan migrate`.

**New**
- Sales ↔ Inventory integration — see `docs/SALES-INVENTORY.md`.
- Dine-in table picker (buttons 1…N) with the number of tables in Admin → Settings; validated on the server.
- Settings: number of tables, default estimate alert level.
- Inventory report: "Drinks served per opened unit" section.
- Deployment to Render: `Dockerfile`, `docker/`, `.dockerignore`, `render.yaml`, `.gitattributes`
  (see `docs/SETUP.md` → Deploying to Render).

**Changed**
- Order details no longer show "Refunded" twice.
- `config/livewire.php` also sets the Livewire 4 layout key (harmless on Livewire 3).
- `.env.example` defaults to a local PostgreSQL database; README rewritten for teammates.

**Demo data:** `php artisan db:seed --class=SalesInventoryLinkSeeder` links the demo menu to the demo
inventory. It is safe to run more than once and on an already-seeded database.
