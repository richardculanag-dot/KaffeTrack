<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SECURITY (Supabase): every table in the `public` schema is exposed through Supabase's
 * auto-generated REST API (PostgREST). Anyone holding the project's public "anon" key could
 * read or write tables that do not have Row Level Security enabled.
 *
 * KaffeTrack talks to Postgres directly as the table owner (the `postgres` role), which is
 * not subject to RLS, so enabling RLS with NO policies keeps the app working while blocking
 * all access through the REST API.
 *
 * Runs only on PostgreSQL; harmless on a plain local Postgres.
 */
return new class extends Migration
{
    private array $tables = [
        'users', 'password_reset_tokens', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches',
        'failed_jobs', 'migrations', 'settings', 'categories', 'products', 'product_variants', 'addons',
        'addon_product', 'discounts', 'suppliers', 'item_categories', 'inventory_items', 'stock_ins',
        'stock_batches', 'item_usages', 'stock_movements', 'cash_shifts', 'cash_movements', 'orders',
        'order_items', 'order_item_addons', 'payments', 'refunds', 'expense_categories', 'expenses',
        'incomes', 'audit_logs',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->tables as $table) {
            DB::statement("ALTER TABLE IF EXISTS \"{$table}\" ENABLE ROW LEVEL SECURITY");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->tables as $table) {
            DB::statement("ALTER TABLE IF EXISTS \"{$table}\" DISABLE ROW LEVEL SECURITY");
        }
    }
};
