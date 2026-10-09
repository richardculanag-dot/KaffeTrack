<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KaffeTrack — Users (Manager / Staff), password resets and sessions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('username', 50);
            $table->string('phone', 30)->nullable();
            $table->string('role', 20)->default('staff');       // manager | staff
            $table->string('password');
            // Hashed 4–6 digit PIN used by managers to authorise refunds at the POS.
            $table->string('pin')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index('role');
        });

        // Partial unique indexes: a soft-deleted account must not block re-using its email/username.
        DB::statement('CREATE UNIQUE INDEX users_email_unique ON users (lower(email)) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX users_username_unique ON users (lower(username)) WHERE deleted_at IS NULL');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('manager','staff'))");

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
