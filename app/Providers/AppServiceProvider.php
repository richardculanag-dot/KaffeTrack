<?php

namespace App\Providers;

use App\Models;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Development safety nets. Lazy-loading (N+1 queries) is LOGGED rather than thrown, so a missed
        // eager-load shows up in storage/logs during development without breaking a live demo.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::handleLazyLoadingViolationUsing(function ($model, string $relation) {
            logger()->warning('Lazy loading detected: '.$model::class.'::'.$relation);
        });
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // Short, stable type names in polymorphic columns (audit_logs, stock_movements)
        // instead of PHP class names — readable in the DB and safe if classes are renamed.
        Relation::enforceMorphMap([
            'user' => Models\User::class,
            'setting' => Models\Setting::class,
            'category' => Models\Category::class,
            'product' => Models\Product::class,
            'product_variant' => Models\ProductVariant::class,
            'addon' => Models\Addon::class,
            'discount' => Models\Discount::class,
            'supplier' => Models\Supplier::class,
            'item_category' => Models\ItemCategory::class,
            'inventory_item' => Models\InventoryItem::class,
            'stock_in' => Models\StockIn::class,
            'stock_batch' => Models\StockBatch::class,
            'item_usage' => Models\ItemUsage::class,
            'stock_movement' => Models\StockMovement::class,
            'cash_shift' => Models\CashShift::class,
            'cash_movement' => Models\CashMovement::class,
            'order' => Models\Order::class,
            'order_item' => Models\OrderItem::class,
            'payment' => Models\Payment::class,
            'refund' => Models\Refund::class,
            'expense_category' => Models\ExpenseCategory::class,
            'expense' => Models\Expense::class,
            'income' => Models\Income::class,
            'audit_log' => Models\AuditLog::class,
        ]);

        // SECURITY: Livewire actions (button clicks) are separate requests to /livewire/update. Re-run the
        // page's role and active-account checks on every one of them, not only when the page first loads —
        // otherwise a demoted or deactivated user with a page still open could keep using manager actions.
        Livewire::addPersistentMiddleware([
            \App\Http\Middleware\EnsureUserHasRole::class,
            \App\Http\Middleware\EnsureUserIsActive::class,
        ]);

        // Role-based authorisation. Managers can do everything; staff get the POS and stock-out.
        Gate::define('manage', fn (Models\User $user) => $user->isManager());

        // Authentication events → Audit / Logs
        Event::listen(function (Login $event) {
            /** @var Models\User $user */
            $user = $event->user;
            $user->forceFill(['last_login_at' => now()])->saveQuietly();
            AuditLogger::log('auth', 'login', "{$user->name} logged in", $user, user: $user);
        });

        Event::listen(function (Logout $event) {
            if ($event->user) {
                AuditLogger::log('auth', 'logout', "{$event->user->name} logged out", $event->user, user: $event->user);
            }
        });

        Event::listen(function (Failed $event) {
            $login = $event->credentials['login'] ?? $event->credentials['email'] ?? $event->credentials['username'] ?? 'unknown';
            AuditLogger::log('auth', 'login_failed', "Failed login attempt for \"{$login}\"", $event->user, user: null);
        });
    }
}
