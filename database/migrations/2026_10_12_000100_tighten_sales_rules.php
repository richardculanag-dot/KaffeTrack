<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Update 2 — sales safety rules found during the pre-defense inspection.
 *
 *  - A fully discounted (₱0) order could not be paid: payments required amount > 0, so the POS showed an
 *    error page. A ₱0 payment is now allowed (a complimentary drink is still recorded as paid).
 *  - One payment and one refund per order, enforced by the database as well as the app, so two
 *    simultaneous clicks can never record the same payment or refund twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_amount_check');
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_check CHECK (amount >= 0)');

        // Only add the unique indexes when existing data already satisfies them (it always should).
        foreach (['payments', 'refunds'] as $table) {
            $duplicates = DB::table($table)->select('order_id')->groupBy('order_id')->havingRaw('COUNT(*) > 1')->exists();
            if (! $duplicates) {
                DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$table}_one_per_order ON {$table} (order_id)");
            }
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS refunds_one_per_order');
        DB::statement('DROP INDEX IF EXISTS payments_one_per_order');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_amount_check');
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_check CHECK (amount > 0)');
    }
};
