<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Update 3.2 — end-of-shift stock check:
 *  - An item can be counted more than once in a shift: if it is sold again after its count, it is listed
 *    again before the drawer can close (one count per item per shift missed those sales).
 *  - Expired units missing from the shelf are recorded as expired (written off), not as opened for use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_stock_checks', function (Blueprint $table) {
            $table->dropUnique(['cash_shift_id', 'inventory_item_id']);
            $table->index(['cash_shift_id', 'inventory_item_id', 'checked_at']);
            $table->unsignedInteger('expired')->default(0)->after('wasted');
        });
        DB::statement('ALTER TABLE shift_stock_checks DROP CONSTRAINT IF EXISTS shift_stock_checks_split_check');
        DB::statement('ALTER TABLE shift_stock_checks ADD CONSTRAINT shift_stock_checks_split_check CHECK (stocked_out + wasted + expired <= expected_sealed)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE shift_stock_checks DROP CONSTRAINT IF EXISTS shift_stock_checks_split_check');
        // Keep only the latest count per item per shift so the old one-per-shift rule can be restored.
        DB::statement('DELETE FROM shift_stock_checks a USING shift_stock_checks b
            WHERE a.cash_shift_id = b.cash_shift_id AND a.inventory_item_id = b.inventory_item_id AND a.id < b.id');
        Schema::table('shift_stock_checks', function (Blueprint $table) {
            $table->dropIndex(['cash_shift_id', 'inventory_item_id', 'checked_at']);
            $table->dropColumn('expired');
            $table->unique(['cash_shift_id', 'inventory_item_id']);
        });
        DB::statement('ALTER TABLE shift_stock_checks ADD CONSTRAINT shift_stock_checks_split_check CHECK (stocked_out + wasted <= expected_sealed)');
    }
};
