<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sales ↔ Inventory link ("drinks served per open container").
 *
 * HEIM keeps its open-container method: staff stock out ONE unit, use it, and mark it depleted.
 * This adds the missing connection between the two modules:
 *
 *   Product ──uses──► Inventory item           (product_ingredients, no quantities)
 *   Add-on ──adds / replaces a role──► item    (addon_ingredient_rules, e.g. Oat milk replaces "milk")
 *   Order item ──counted against──► the open unit (order_item_ingredients → item_usages)
 *
 * When a drink is marked Ready it is counted once against the item's open unit. The unit's
 * "servings used" is compared with how many drinks a unit usually serves (learned from past
 * units, or a manager's starting guess) to warn BEFORE it runs out. It is an estimate only:
 * nothing here changes quantity_on_hand or the stock_movements ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            // Estimate drinks served per opened unit for this item?
            $table->boolean('tracks_servings')->default(false)->after('track_expiry');
            // Manager's starting guess until enough units have been used up to learn from.
            $table->unsignedInteger('expected_servings')->nullable()->after('tracks_servings');
            // Warn at this % of the expected servings (null = system default in Settings).
            $table->unsignedTinyInteger('servings_alert_percent')->nullable()->after('expected_servings');
        });
        DB::statement('ALTER TABLE inventory_items ADD CONSTRAINT inventory_items_servings_check CHECK ((expected_servings IS NULL OR expected_servings > 0) AND (servings_alert_percent IS NULL OR servings_alert_percent BETWEEN 10 AND 100))');

        // What a product uses. "role" names the job the item does in the drink (milk, espresso…),
        // so an add-on can replace it. "servings" lets one drink count as more than one serving.
        Schema::create('product_ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            $table->string('role', 30);
            $table->decimal('servings', 5, 2)->default(1);
            $table->timestamps();

            $table->unique(['product_id', 'role']);
            $table->index('inventory_item_id');
        });
        DB::statement('ALTER TABLE product_ingredients ADD CONSTRAINT product_ingredients_servings_check CHECK (servings > 0)');

        // What an add-on changes: "replace" swaps the item in a role (Oat milk → milk role);
        // "add" adds an item (Extra espresso shot → +1 serving of beans).
        Schema::create('addon_ingredient_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('addon_id')->constrained()->cascadeOnDelete();
            $table->string('action', 10);                       // add | replace
            $table->string('role', 30);
            $table->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            $table->decimal('servings', 5, 2)->default(1);      // used by "add" (replace keeps the role's servings)
            $table->timestamps();

            $table->unique(['addon_id', 'role']);
            $table->index('inventory_item_id');
        });
        DB::statement("ALTER TABLE addon_ingredient_rules ADD CONSTRAINT addon_ingredient_rules_action_check CHECK (action IN ('add','replace'))");
        DB::statement('ALTER TABLE addon_ingredient_rules ADD CONSTRAINT addon_ingredient_rules_servings_check CHECK (servings > 0)');

        // Snapshot of which items each sold line uses, resolved once when the order is placed
        // (like product names and prices, so later edits never rewrite history), then counted
        // once against an open unit when the drink is marked Ready.
        Schema::create('order_item_ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            $table->decimal('servings', 8, 2);                  // per-drink servings × line quantity
            $table->foreignId('item_usage_id')->nullable()->constrained()->nullOnDelete(); // null = no unit was open
            $table->timestamp('counted_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['order_item_id', 'inventory_item_id']);   // a drink can never be counted twice
            $table->index('item_usage_id');
            $table->index(['inventory_item_id', 'counted_at']);
        });
        DB::statement('ALTER TABLE order_item_ingredients ADD CONSTRAINT order_item_ingredients_servings_check CHECK (servings > 0)');

        Schema::table('item_usages', function (Blueprint $table) {
            $table->decimal('servings_used', 8, 2)->default(0)->after('status');
            $table->decimal('expected_servings', 8, 2)->nullable()->after('servings_used');   // snapshot at opening
            $table->unsignedTinyInteger('alert_percent')->nullable()->after('expected_servings');
            $table->timestamp('alerted_at')->nullable();         // reached the alert % (notified once)
            $table->timestamp('reached_at')->nullable();         // reached 100 % of expected (notified once)
            $table->timestamp('flagged_at')->nullable();         // far past expected → manager exception
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution_note')->nullable();
            // Left out of the "usually serves" average (discarded units, resolved exceptions).
            $table->boolean('exclude_from_history')->default(false);

            $table->index('acknowledged_by');
            $table->index('resolved_by');
        });

        if (DB::getDriverName() === 'pgsql') {
            foreach (['product_ingredients', 'addon_ingredient_rules', 'order_item_ingredients'] as $table) {
                DB::statement("ALTER TABLE \"{$table}\" ENABLE ROW LEVEL SECURITY");
            }
        }
    }

    public function down(): void
    {
        Schema::table('item_usages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('acknowledged_by');
            $table->dropConstrainedForeignId('resolved_by');
            $table->dropColumn(['servings_used', 'expected_servings', 'alert_percent', 'alerted_at', 'reached_at', 'flagged_at',
                'acknowledged_at', 'resolved_at', 'resolution_note', 'exclude_from_history']);
        });
        Schema::dropIfExists('order_item_ingredients');
        Schema::dropIfExists('addon_ingredient_rules');
        Schema::dropIfExists('product_ingredients');
        DB::statement('ALTER TABLE inventory_items DROP CONSTRAINT IF EXISTS inventory_items_servings_check');
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropColumn(['tracks_servings', 'expected_servings', 'servings_alert_percent']);
        });
    }
};
