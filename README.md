# KaffeTrack

A café POS and inventory system for HEIM, built with Laravel 12, Livewire 3, Tailwind CSS 4 and PostgreSQL.

Modules: Dashboard, Point of sale, Order queue, Cash drawer, Inventory (stock in/out, suppliers, movements),
Products, Finance, PDF reports, Users, Audit logs and Archive.

---

## Running it on your computer (after cloning)

### 1. Install these once

| Tool | Version | Check with |
| --- | --- | --- |
| PHP | 8.2 or newer (XAMPP's PHP is fine) | `php -v` |
| Composer | 2.x | `composer -V` |
| Node.js | 20.19+ or 22.12+ | `node -v` |
| PostgreSQL | 17 (EDB installer from postgresql.org; skip Stack Builder) | `services.msc` → `postgresql-x64-17` is Running |

PHP needs the Postgres and zip extensions. In `C:\xampp\php\php.ini`, remove the `;` in front of these
lines, then close and reopen your terminal:

```ini
extension=pdo_pgsql
extension=pgsql
extension=zip
```

`php -m` should now list `pdo_pgsql`, `pgsql` and `zip`. If you have more than one PHP installed,
`php --ini` shows which `php.ini` is actually in use.

### 2. Create an empty local database

Create a database named `kaffetrack` on your local PostgreSQL, using either option:

- **Terminal:** `& "C:\Program Files\PostgreSQL\17\bin\createdb.exe" -U postgres kaffetrack`
- **VS Code:** use a PostgreSQL extension, connect to `localhost:5432` as `postgres` (Standard, not Secure),
  and run `CREATE DATABASE kaffetrack;`

If pgAdmin fails with "An Application Control policy has blocked this file", that is Windows Smart App
Control blocking pgAdmin, not Postgres. Use one of the options above. Do not turn Smart App Control off:
on Windows 11 it cannot be turned back on without resetting Windows.

### 3. Set up the project

From the project folder:

```powershell
composer install
npm install
copy .env.example .env
php artisan key:generate
```

Open `.env` and set `DB_PASSWORD` to **your own** local `postgres` password. Everything else is already
set for a local database.

Use `composer install`, never `composer update` or `composer require`, unless the team agrees on it.
`install` uses the exact package versions in `composer.lock`. KaffeTrack needs **Livewire 3**, and a
plain `composer require livewire/livewire` would pull in Livewire 4, which breaks the app.

### 4. Build the database and demo data

```powershell
php artisan db:show
php artisan migrate:fresh --seed
```

**Check the `db:show` output first:** it must say `127.0.0.1` and `kaffetrack`. `migrate:fresh` deletes
every table in whatever database `.env` points to.

### 5. Run it

```powershell
npm run build
php artisan serve
```

Open http://127.0.0.1:8000. While editing CSS or JS, run `npm run dev` in a second terminal instead of
`npm run build`.

### Demo accounts

| Username | Password | Role | Refund PIN |
| --- | --- | --- | --- |
| `manager` | `Manager@123` | Manager | `1234` |
| `manager2` | `Manager@123` | Manager | `5678` |
| `staff` | `Staff@123` | Staff | — |
| `carlo` | `Staff@123` | Staff | — |
| `jasmine` | `Staff@123` | Staff | — |

---

## Team rules

- **Never commit `.env`.** It holds passwords and is already in `.gitignore`. Share settings through
  `.env.example` with the values left blank.
- **Everyone develops on their own local database.** Only the person managing the deployment needs the
  Supabase credentials, and they should be shared privately, never in the repository or a group chat.
- **Never run `migrate:fresh` or `db:seed` against Supabase.** `migrate:fresh` drops every table, and
  `db:seed` duplicates the demo data. Always check `php artisan db:show` first.
- **Database changes go in a new migration file** (`php artisan make:migration ...`). Never edit a
  migration that has already been pushed. The live site runs new migrations automatically on deploy.
- After pulling teammates' changes: `composer install`, `npm install`, `php artisan migrate`.

## Sales ↔ Inventory link (how stock and sales connect)

Products list which inventory items they use (no amounts). Staff never deal with inventory while taking
orders; when the cash drawer is closed, KaffeTrack lists the items that shift's sales used, staff count
the sealed units left, and any difference is recorded as stock-outs or waste under their name. No
estimates, and nothing blocks a sale. Details: `docs/SALES-INVENTORY.md`.

## More documentation

- `docs/SETUP.md`: full setup, Render deployment and troubleshooting
- `docs/SALES-INVENTORY.md`: how sales and inventory are connected
- `docs/CHANGES.md`: what changed since the first build
- `docs/HCI-DESIGN.md`: design and usability decisions
- `docs/TESTING.md`: test checklist
- `docs/erd/`: entity-relationship diagrams
