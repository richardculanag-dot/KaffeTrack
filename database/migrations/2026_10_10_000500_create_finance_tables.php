<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance module.
 *  - Sales income is derived from paid orders (minus refunds); it is not duplicated here.
 *  - `incomes` holds non-sales income (catering, events, misc).
 *  - `expenses` holds operating costs. Supplier stock-ins automatically create an
 *    "Inventory Purchases" expense linked through stock_in_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('description')->nullable();
            $table->boolean('is_system')->default(false);  // system categories cannot be archived
            $table->timestamps();
            $table->softDeletes();
        });
        DB::statement('CREATE UNIQUE INDEX expense_categories_name_unique ON expense_categories (lower(name)) WHERE deleted_at IS NULL');

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('stock_in_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->string('description');
            $table->decimal('amount', 12, 2);
            $table->date('expense_date');
            $table->string('payment_method', 20)->nullable();       // cash | bank_transfer | card | online | check
            $table->string('reference_number', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('expense_category_id');
            $table->index('supplier_id');
            $table->index('stock_in_id');
            $table->index('recorded_by');
            $table->index('expense_date');
        });
        DB::statement('ALTER TABLE expenses ADD CONSTRAINT expenses_amount_check CHECK (amount > 0)');

        Schema::create('incomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->string('source', 150);
            $table->decimal('amount', 12, 2);
            $table->date('income_date');
            $table->string('payment_method', 20)->nullable();
            $table->string('reference_number', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('recorded_by');
            $table->index('income_date');
        });
        DB::statement('ALTER TABLE incomes ADD CONSTRAINT incomes_amount_check CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('incomes');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
    }
};
