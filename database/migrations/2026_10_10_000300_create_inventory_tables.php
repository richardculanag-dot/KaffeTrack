<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory module — "open-container" model.
 *
 *   Supplier ──► Stock In (header) ──► Stock Batch (line, qty + unit cost + expiry)
 *   Stock Out: one unit leaves storage and becomes an Item Usage (status = in_use)
 *   Mark Depleted: the open unit is fully consumed (status = depleted)
 *
 * Every change to storage quantity is written to stock_movements (an append-only ledger).
 * Only ONE unit of an item may be in use at a time — enforced by a partial unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('contact_person', 100)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        DB::statement('CREATE UNIQUE INDEX suppliers_name_unique ON suppliers (lower(name)) WHERE deleted_at IS NULL');

        Schema::create('item_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        DB::statement('CREATE UNIQUE INDEX item_categories_name_unique ON item_categories (lower(name)) WHERE deleted_at IS NULL');

        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('default_supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('name', 150);
            $table->string('sku', 40)->nullable();
            $table->string('unit', 40);                          // e.g. "bag (1 kg)", "carton (1 L)"
            // Units currently in storage. Kept in sync with stock_batches.quantity_remaining
            // inside the same DB transaction by InventoryService (never edited directly).
            $table->integer('quantity_on_hand')->default(0);
            $table->unsignedInteger('reorder_level')->default(0); // low-stock threshold
            $table->boolean('track_expiry')->default(false);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('item_category_id');
            $table->index('default_supplier_id');
        });
        DB::statement('CREATE UNIQUE INDEX inventory_items_sku_unique ON inventory_items (lower(sku)) WHERE deleted_at IS NULL AND sku IS NOT NULL');
        DB::statement('ALTER TABLE inventory_items ADD CONSTRAINT inventory_items_qty_check CHECK (quantity_on_hand >= 0)');

        Schema::create('stock_ins', function (Blueprint $table) {
            $table->id();
            $table->string('reference_no', 30)->unique();        // SI-20261010-0001
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->date('received_date');
            $table->string('invoice_number', 60)->nullable();
            $table->decimal('total_cost', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('supplier_id');
            $table->index('received_by');
            $table->index('received_date');
        });

        Schema::create('stock_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_in_id')->nullable()->constrained()->cascadeOnDelete(); // null = manual adjustment batch
            $table->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            $table->string('batch_code', 40)->nullable();
            $table->unsignedInteger('quantity_received');
            $table->unsignedInteger('quantity_remaining');
            $table->decimal('unit_cost', 12, 2);
            $table->date('expiry_date')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('stock_in_id');
            $table->index(['inventory_item_id', 'expiry_date']);  // FEFO lookup
        });
        DB::statement('ALTER TABLE stock_batches ADD CONSTRAINT stock_batches_qty_check CHECK (quantity_received > 0 AND quantity_remaining >= 0 AND quantity_remaining <= quantity_received)');
        DB::statement('ALTER TABLE stock_batches ADD CONSTRAINT stock_batches_cost_check CHECK (unit_cost >= 0)');

        Schema::create('item_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('in_use');      // in_use | depleted | discarded
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('opened_at');
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('inventory_item_id');
            $table->index('stock_batch_id');
            $table->index('opened_by');
            $table->index('closed_by');
            $table->index('status');
        });
        DB::statement("ALTER TABLE item_usages ADD CONSTRAINT item_usages_status_check CHECK (status IN ('in_use','depleted','discarded'))");
        // Business rule: an item cannot be stocked out again while one unit is still open.
        DB::statement("CREATE UNIQUE INDEX item_usages_one_open_per_item ON item_usages (inventory_item_id) WHERE status = 'in_use' AND deleted_at IS NULL");

        // Append-only ledger. No soft deletes / updates — corrections are new rows.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            // stock_in | stock_out | adjustment_in | adjustment_out | waste | expired
            $table->string('type', 20);
            $table->integer('quantity');                          // signed: + into storage, − out of storage
            $table->integer('balance_after');
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->nullableMorphs('reference');                  // StockIn, ItemUsage, …
            $table->string('reason')->nullable();
            $table->timestamp('created_at');

            $table->index(['inventory_item_id', 'created_at']);
            $table->index('stock_batch_id');
            $table->index('user_id');
            $table->index('type');
        });
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_type_check CHECK (type IN ('stock_in','stock_out','adjustment_in','adjustment_out','waste','expired'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('item_usages');
        Schema::dropIfExists('stock_batches');
        Schema::dropIfExists('stock_ins');
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('item_categories');
        Schema::dropIfExists('suppliers');
    }
};
