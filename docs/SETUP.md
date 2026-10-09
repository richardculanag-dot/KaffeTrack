# KaffeTrack — setup guide

KaffeTrack: A Café POS and Inventory System for HEIM
Laravel 12 · Livewire 3 · Alpine.js · Tailwind CSS 4 · PostgreSQL (Supabase) · DomPDF

---

## Before you start

> **Warning — step 5 erases the database.** `migrate:fresh` drops every table in the Supabase
> `public` schema and rebuilds it. That is what you want on a new project, but never run it
> against a database that already holds real café data.

You need PHP 8.2+ with the `pdo_pgsql`, `mbstring`, `gd` and `zip` extensions, Composer, and Node.js 20+.

## 1. Install the new packages

From the project folder:

```bash
composer require livewire/livewire:^3.6 barryvdh/laravel-dompdf:^3.1
npm install
npm install chart.js@^4 @fontsource-variable/bricolage-grotesque @fontsource-variable/instrument-sans
```

## 2. Copy the KaffeTrack files

Extract `kaffetrack-files.zip` into the project root and allow it to **replace** existing files.
These Laravel files are replaced on purpose:

| File | What changed |
| --- | --- |
| `app/Models/User.php` | Roles, PIN, soft deletes, audit logging |
| `app/Providers/AppServiceProvider.php` | Morph map, `manage` gate, login/logout audit listeners |
| `bootstrap/app.php` | `role` and `active` middleware, guest/user redirects, trusted proxies |
| `config/app.php` | `timezone` reads `APP_TIMEZONE` (default Asia/Manila) |
| `config/filesystems.php` | New `uploads` disk for product photos (no `storage:link` needed) |
| `database/migrations/0001_01_01_000000_create_users_table.php` | KaffeTrack users table |
| `database/seeders/DatabaseSeeder.php` | Calls the KaffeTrack seeders |
| `resources/css/app.css`, `resources/js/app.js` | Design system, fonts, Alpine stores, charts |
| `routes/web.php` | All KaffeTrack routes |
| `tests/Feature/ExampleTest.php` | Database-free smoke tests |
| `.env.example` | KaffeTrack settings with comments |

You can delete `resources/views/welcome.blade.php`; it is no longer used.

## 3. Register the helper file

Open `composer.json` and add the `files` line inside `autoload`:

```json
"autoload": {
    "files": ["app/Support/helpers.php"],
    "psr-4": {
        "App\\": "app/",
        "Database\\Factories\\": "database/factories/",
        "Database\\Seeders\\": "database/seeders/"
    }
},
```

Then run:

```bash
composer dump-autoload
```

## 4. Check `.env`

Compare your `.env` with the new `.env.example`. The important lines:

```dotenv
APP_NAME=KaffeTrack
APP_URL=http://localhost:8000
APP_TIMEZONE=Asia/Manila

DB_CONNECTION=pgsql
DB_HOST=aws-0-ap-south-1.pooler.supabase.com   # no http:// in front
DB_PORT=5432                                   # session pooler
DB_DATABASE=postgres
DB_USERNAME=postgres.<your-project-ref>        # pooler usernames include the project ref
DB_PASSWORD=<your database password>
DB_SSLMODE=require

SESSION_DRIVER=file
CACHE_STORE=file
```

Copy the exact host and username from Supabase → **Project Settings → Database → Connection string →
Session pooler**. Then clear cached config:

```bash
php artisan config:clear
```

## 5. Build the database and demo data

```bash
php artisan migrate:fresh --seed
```

This creates 34 tables, enables Row Level Security on all of them, and seeds 90 days of realistic
demo history. Against Supabase from the Philippines it takes **about 5–8 minutes** (≈ 4,800 queries
across the network) — let it finish. For a faster run, set `SEED_INVENTORY_DAYS=7` in `.env`; for an
empty production start, set `SEED_DEMO_DATA=false`.

## 6. Run it

```bash
npm run build        # or keep `npm run dev` running while you work
php artisan serve
```

Open http://localhost:8000.

| Role | Username | Password | Refund PIN |
| --- | --- | --- | --- |
| Manager | `manager` | `Manager@123` | 1234 |
| Manager | `manager2` | `Manager@123` | 5678 |
| Staff | `staff` | `Staff@123` | — |
| Staff | `carlo`, `jasmine` | `Staff@123` | — |

**Change these passwords before real use** (Users → reset password, and My profile → change PIN).

A typical first demo: sign in as `staff` → open the cash drawer → take an order at the POS →
mark it ready and hand it over in the Order queue → stock out a carton of milk in Stock on hand →
sign in as `manager` → see it all on the Dashboard, Reports and Audit logs.

---

## Deploying (MonsterASP or any PHP host)

1. Confirm the plan runs **PHP 8.2+ with `pdo_pgsql` enabled**. Many Windows/.NET-oriented hosts do not
   enable it by default; without it Laravel cannot reach Supabase.
2. Build locally: `npm run build` and `composer install --no-dev --optimize-autoloader`.
3. Upload the whole project **including `vendor/` and `public/build/`**. Point the site's document root
   at the `public/` folder (never the project root, which would expose `.env`).
4. Production `.env`: `APP_ENV=production`, `APP_DEBUG=false`, the real `APP_URL` (https), and the same
   Supabase settings. Generate a new key with `php artisan key:generate` if the host gives you a shell.
5. Make `storage/`, `bootstrap/cache/` and `public/uploads/` writable by the web server.
6. Run once: `php artisan migrate --force` (not `migrate:fresh`) and `php artisan optimize`.

## Troubleshooting

| Symptom | Fix |
| --- | --- |
| `could not translate host name "http://…"` | Remove `http://` from `DB_HOST` |
| `password authentication failed for user "postgres"` | Use the pooler username `postgres.<project-ref>` |
| `prepared statement … does not exist` | You are on the transaction pooler (port 6543); use port 5432 |
| Styles missing / Vite manifest not found | Run `npm run build` |
| `Call to undefined function peso()` | Step 3 was skipped: add the `files` entry and run `composer dump-autoload` |
| PDF shows boxes instead of ₱ | Keep the default DejaVu Sans font in `resources/views/reports/pdf.blade.php` |
| Product photo upload fails on the host | Make `public/uploads/` writable |

## Testing safely

`php artisan test` runs database-free smoke tests only. Never add `RefreshDatabase` tests unless
`phpunit.xml` points at a separate throw-away database: those tests wipe every table they can reach.
