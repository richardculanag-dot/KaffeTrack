<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Products module: categories, products, size/price variants, add-ons and discounts.
 * Products are intentionally NOT linked to inventory items (see docs: open-container model).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('description')->nullable();
            $table->string('icon', 50)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        DB::statement('CREATE UNIQUE INDEX categories_name_unique ON categories (lower(name)) WHERE deleted_at IS NULL');

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->string('code', 30)->nullable();
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->boolean('is_available')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('category_id');
        });
        DB::statement('CREATE UNIQUE INDEX products_code_unique ON products (lower(code)) WHERE deleted_at IS NULL AND code IS NOT NULL');

        // Every product has at least one variant (e.g. "12 oz Hot", "16 oz Iced", or "Regular").
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->decimal('price', 12, 2);
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('product_id');
        });
        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT product_variants_price_check CHECK (price >= 0)');

        Schema::create('addons', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->decimal('price', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        DB::statement('ALTER TABLE addons ADD CONSTRAINT addons_price_check CHECK (price >= 0)');

        // Which add-ons may be applied to which product.
        Schema::create('addon_product', function (Blueprint $table) {
            $table->foreignId('addon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->primary(['addon_id', 'product_id']);
            $table->index('product_id');
        });

        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('type', 20);                       // percentage | fixed
            $table->decimal('value', 12, 2);
            // Senior Citizen / PWD (RA 9994 / RA 10754): VAT is removed before the discount is applied.
            $table->boolean('is_vat_exempt')->default(false);
            $table->boolean('requires_id')->default(false);   // cashier must record an ID number
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        DB::statement("ALTER TABLE discounts ADD CONSTRAINT discounts_type_check CHECK (type IN ('percentage','fixed'))");
        DB::statement("ALTER TABLE discounts ADD CONSTRAINT discounts_value_check CHECK (value >= 0 AND (type <> 'percentage' OR value <= 100))");
    }

    public function down(): void
    {
        Schema::dropIfExists('discounts');
        Schema::dropIfExists('addon_product');
        Schema::dropIfExists('addons');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
    }
};
