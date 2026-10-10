<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Update 3 — the Sales ↔ Inventory link without estimates.
 *
 * Kept from Update 1: which inventory items each product uses (product_ingredients), what add-ons
 * change (addon_ingredient_rules) and, for every sold line, which items it used (order_item_ingredients).
 *
 * Removed: everything that ESTIMATED usage ("drinks served per unit", alert levels, over-usage review).
 *
 * Added: shift_stock_checks. When the cash drawer is closed, staff count the sealed units left for
 * every item the shift's sales used. The difference from the system's count is recorded as real
 * stock-outs (or waste), so the stock ledger stays consistent with sales without interrupting service.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_stock_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('expected_sealed');            // sealed units the system had in storage
            $table->unsignedInteger('counted_sealed');             // sealed units staff counted on the shelf
            $table->unsignedInteger('stocked_out')->default(0);    // difference recorded as opened for use
            $table->unsignedInteger('wasted')->default(0);         // difference recorded as thrown away
            $table->boolean('open_unit_finished')->nullable();     // null = no open unit to ask about
            $table->string('note')->nullable();
            $table->foreignId('checked_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('checked_at');

            $table->unique(['cash_shift_id', 'inventory_item_id']);   // one line per item per shift
            $table->index('inventory_item_id');
            $table->index('checked_by');
        });
        DB::statement('ALTER TABLE shift_stock_checks ADD CONSTRAINT shift_stock_checks_split_check CHECK (stocked_out + wasted <= expected_sealed)');

        // ── Remove the estimate columns ─────────────────────────────────────────
        DB::statement('ALTER TABLE inventory_items DROP CONSTRAINT IF EXISTS inventory_items_servings_check');
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropColumn(['tracks_servings', 'expected_servings', 'servings_alert_percent']);
        });

        DB::statement('ALTER TABLE product_ingredients DROP CONSTRAINT IF EXISTS product_ingredients_servings_check');
        Schema::table('product_ingredients', fn (Blueprint $table) => $table->dropColumn('servings'));

        DB::statement('ALTER TABLE addon_ingredient_rules DROP CONSTRAINT IF EXISTS addon_ingredient_rules_servings_check');
        Schema::table('addon_ingredient_rules', fn (Blueprint $table) => $table->dropColumn('servings'));

        DB::statement('ALTER TABLE order_item_ingredients DROP CONSTRAINT IF EXISTS order_item_ingredients_servings_check');
        Schema::table('order_item_ingredients', function (Blueprint $table) {
            $table->dropIndex(['inventory_item_id', 'counted_at']);
            $table->dropConstrainedForeignId('item_usage_id');
            $table->dropColumn(['servings', 'counted_at']);
        });

        Schema::table('item_usages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('acknowledged_by');
            $table->dropConstrainedForeignId('resolved_by');
            $table->dropColumn(['servings_used', 'expected_servings', 'alert_percent', 'alerted_at', 'reached_at', 'flagged_at',
                'acknowledged_at', 'resolved_at', 'resolution_note', 'exclude_from_history']);
        });

        DB::table('settings')->where('key', 'servings_alert_percent')->delete();

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "shift_stock_checks" ENABLE ROW LEVEL SECURITY');
        }
    }

    public function down(): void
    {
        // Restores the Update 1 columns (empty — estimate history is not recoverable) and drops the checks.
        Schema::dropIfExists('shift_stock_checks');

        Schema::table('inventory_items', function (Blueprint $table) {
            $table->boolean('tracks_servings')->default(false)->after('track_expiry');
            $table->unsignedInteger('expected_servings')->nullable()->after('tracks_servings');
            $table->unsignedTinyInteger('servings_alert_percent')->nullable()->after('expected_servings');
        });
        Schema::table('product_ingredients', fn (Blueprint $table) => $table->decimal('servings', 5, 2)->default(1));
        Schema::table('addon_ingredient_rules', fn (Blueprint $table) => $table->decimal('servings', 5, 2)->default(1));
        Schema::table('order_item_ingredients', function (Blueprint $table) {
            $table->decimal('servings', 8, 2)->default(1);
            $table->foreignId('item_usage_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('counted_at')->nullable();
            $table->index(['inventory_item_id', 'counted_at']);
        });
        Schema::table('item_usages', function (Blueprint $table) {
            $table->decimal('servings_used', 8, 2)->default(0);
            $table->decimal('expected_servings', 8, 2)->nullable();
            $table->unsignedTinyInteger('alert_percent')->nullable();
            $table->timestamp('alerted_at')->nullable();
            $table->timestamp('reached_at')->nullable();
            $table->timestamp('flagged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution_note')->nullable();
            $table->boolean('exclude_from_history')->default(false);
        });
    }
};
