<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sales / POS module: cash drawer shifts, orders, line items, payments and refunds.
 *
 * Order status flow:   pending ──► ready ──► completed
 *                         └──────────┴──► cancelled (unpaid only)
 * A completed, paid order can later be refunded (manager PIN required).
 * Item names/prices are snapshotted so editing a product never rewrites sales history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('opened_at');
            $table->decimal('opening_cash', 12, 2);
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->decimal('expected_cash', 12, 2)->nullable();   // computed at close
            $table->decimal('counted_cash', 12, 2)->nullable();    // end-of-day physical count
            $table->decimal('variance', 12, 2)->nullable();        // counted − expected
            $table->jsonb('denominations')->nullable();            // {"1000": 2, "500": 3, …}
            $table->string('status', 10)->default('open');         // open | closed
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('opened_by');
            $table->index('closed_by');
            $table->index('opened_at');
        });
        DB::statement("ALTER TABLE cash_shifts ADD CONSTRAINT cash_shifts_status_check CHECK (status IN ('open','closed'))");
        // Single cash drawer: only one shift may be open at a time.
        DB::statement("CREATE UNIQUE INDEX cash_shifts_one_open ON cash_shifts ((status)) WHERE status = 'open' AND deleted_at IS NULL");

        // Petty cash in/out of the drawer during a shift (e.g. change fund top-up, buying ice).
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('type', 10);                            // cash_in | cash_out
            $table->decimal('amount', 12, 2);
            $table->string('reason');
            $table->timestamps();

            $table->index('cash_shift_id');
            $table->index('user_id');
        });
        DB::statement("ALTER TABLE cash_movements ADD CONSTRAINT cash_movements_type_check CHECK (type IN ('cash_in','cash_out'))");
        DB::statement('ALTER TABLE cash_movements ADD CONSTRAINT cash_movements_amount_check CHECK (amount > 0)');

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 30)->unique();           // ORD-20261010-0001
            $table->unsignedInteger('queue_number');                // daily 1, 2, 3… called out to customers
            $table->foreignId('user_id')->constrained()->restrictOnDelete();        // cashier
            $table->foreignId('cash_shift_id')->nullable()->constrained()->nullOnDelete();
            $table->string('order_type', 10);                       // dine_in | takeout
            $table->string('customer_name', 100)->nullable();
            $table->string('table_number', 10)->nullable();
            $table->string('status', 15)->default('pending');        // pending | ready | completed | cancelled | refunded
            $table->string('payment_status', 10)->default('unpaid'); // unpaid | paid | refunded

            // Money (all VAT-inclusive peso amounts, see App\Services\OrderPricing)
            $table->decimal('subtotal', 12, 2);                     // Σ line totals before discount
            $table->foreignId('discount_id')->nullable()->constrained()->nullOnDelete();
            $table->string('discount_name', 100)->nullable();       // snapshot
            $table->string('discount_id_number', 50)->nullable();   // Senior/PWD ID no.
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('vatable_sales', 12, 2)->default(0);
            $table->decimal('vat_amount', 12, 2)->default(0);
            $table->decimal('vat_exempt_sales', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2);

            $table->text('notes')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id');
            $table->index('cash_shift_id');
            $table->index('discount_id');
            $table->index(['status', 'created_at']);
            $table->index('created_at');
        });
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_type_check CHECK (order_type IN ('dine_in','takeout'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('pending','ready','completed','cancelled','refunded'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_payment_status_check CHECK (payment_status IN ('unpaid','paid','refunded'))");
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_amounts_check CHECK (subtotal >= 0 AND discount_amount >= 0 AND total_amount >= 0)');

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name', 150);                   // snapshot
            $table->string('variant_name', 60);                    // snapshot
            $table->decimal('unit_price', 12, 2);                  // variant price at time of sale
            $table->decimal('addons_total', 12, 2)->default(0);    // per unit
            $table->unsignedInteger('quantity');
            $table->decimal('line_total', 12, 2);                  // (unit_price + addons_total) × quantity
            $table->string('notes')->nullable();                   // "less ice", "extra hot"
            $table->timestamps();

            $table->index('order_id');
            $table->index('product_id');
            $table->index('product_variant_id');
        });
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_qty_check CHECK (quantity > 0)');

        Schema::create('order_item_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('addon_id')->nullable()->constrained()->nullOnDelete();
            $table->string('addon_name', 100);                     // snapshot
            $table->decimal('price', 12, 2);                       // snapshot

            $table->index('order_item_id');
            $table->index('addon_id');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('cash_shift_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method', 10);                          // cash | card | online
            $table->string('provider', 50)->nullable();            // GCash, Maya, Visa, Mastercard …
            $table->string('reference_number', 100)->nullable();   // required for card / online
            $table->decimal('amount', 12, 2);                      // amount applied to the order
            $table->decimal('amount_tendered', 12, 2)->nullable(); // cash handed over
            $table->decimal('change_amount', 12, 2)->default(0);
            $table->timestamps();

            $table->index('order_id');
            $table->index('received_by');
            $table->index('cash_shift_id');
            $table->index(['method', 'created_at']);
        });
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_method_check CHECK (method IN ('cash','card','online'))");
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_check CHECK (amount > 0)');

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cash_shift_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('processed_by')->constrained('users')->restrictOnDelete(); // staff at the POS
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();  // manager who entered PIN
            $table->string('method', 10);                          // refund goes back via the original method
            $table->decimal('amount', 12, 2);
            $table->string('reason');
            $table->timestamps();

            $table->index('order_id');
            $table->index('payment_id');
            $table->index('cash_shift_id');
            $table->index('processed_by');
            $table->index('approved_by');
            $table->index('created_at');
        });
        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_amount_check CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_item_addons');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cash_shifts');
    }
};
