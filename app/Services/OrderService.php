<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Addon;
use App\Models\CashShift;
use App\Models\Discount;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * All order state changes go through here so that pricing, numbering, cash-drawer
 * linkage and audit logging are always consistent.
 */
class OrderService
{
    /** Notices from the last markReady() (inventory estimate alerts) for the screen to show. */
    public array $notices = [];

    public function __construct(
        private RecipeResolver $recipes = new RecipeResolver,
        private ServingTracker $servings = new ServingTracker,
    ) {}

    /**
     * Create an order from the POS cart.
     *
     * @param  array<int, array{variant_id:int, quantity:int, addon_ids?:int[], notes?:string}>  $cart
     * @param  array{order_type:string, customer_name?:?string, table_number?:?string, notes?:?string, discount_id?:?int, discount_id_number?:?string}  $meta
     * @param  array{method:string, amount_tendered?:?float, provider?:?string, reference_number?:?string}|null  $payment  null = pay later
     */
    public function place(array $cart, array $meta, ?array $payment = null, ?User $cashier = null, ?Carbon $at = null): Order
    {
        $cashier ??= Auth::user();
        $at ??= now();

        if (empty($cart)) {
            throw new BusinessRuleException('The cart is empty. Add at least one item.');
        }

        $shift = CashShift::current();
        if (! $shift) {
            throw new BusinessRuleException('Open the cash drawer (start a shift) before taking orders.');
        }

        return DB::transaction(function () use ($cart, $meta, $payment, $cashier, $at, $shift) {
            // Prices are always re-read from the database — the browser is never trusted.
            $variants = ProductVariant::with(['product.addons.ingredientRules', 'product.ingredients'])
                ->whereIn('id', collect($cart)->pluck('variant_id'))
                ->get()->keyBy('id');

            $lines = [];
            $subtotal = 0.0;

            foreach ($cart as $row) {
                $variant = $variants[$row['variant_id']] ?? null;
                if (! $variant || ! $variant->product || $variant->product->trashed() || ! $variant->product->is_available) {
                    throw new BusinessRuleException('One of the items is no longer available. Please remove it from the cart.');
                }

                $qty = max(1, (int) $row['quantity']);
                $allowed = $variant->product->addons->where('is_active', true)->keyBy('id');
                $addons = collect($row['addon_ids'] ?? [])->unique()
                    ->map(fn ($id) => $allowed[$id] ?? null)->filter()->values();

                $addonsTotal = round((float) $addons->sum('price'), 2);
                $lineTotal = round(((float) $variant->price + $addonsTotal) * $qty, 2);
                $subtotal += $lineTotal;

                $lines[] = compact('variant', 'qty', 'addons', 'addonsTotal', 'lineTotal') + ['notes' => $row['notes'] ?? null];
            }

            $discount = ! empty($meta['discount_id']) ? Discount::where('is_active', true)->find($meta['discount_id']) : null;
            if ($discount && $discount->requires_id && blank($meta['discount_id_number'] ?? null)) {
                throw new BusinessRuleException("An ID number is required for the {$discount->name} discount.");
            }

            $totals = OrderPricing::compute($subtotal, $discount);
            [$orderNumber, $queueNumber] = $this->nextNumbers($at);

            $order = Order::create([
                'order_number' => $orderNumber,
                'queue_number' => $queueNumber,
                'user_id' => $cashier->id,
                'cash_shift_id' => $shift->id,
                'order_type' => $meta['order_type'],
                'customer_name' => $meta['customer_name'] ?? null,
                'table_number' => $meta['order_type'] === 'dine_in' ? ($meta['table_number'] ?? null) : null,
                'status' => Order::STATUS_PENDING,
                'payment_status' => 'unpaid',
                'subtotal' => $totals['subtotal'],
                'discount_id' => $discount?->id,
                'discount_name' => $discount?->name,
                'discount_id_number' => $discount ? ($meta['discount_id_number'] ?? null) : null,
                'discount_amount' => $totals['discount_amount'],
                'vatable_sales' => $totals['vatable_sales'],
                'vat_amount' => $totals['vat_amount'],
                'vat_exempt_sales' => $totals['vat_exempt_sales'],
                'total_amount' => $totals['total_amount'],
                'notes' => $meta['notes'] ?? null,
                'created_at' => $at,
                'updated_at' => $at,
            ]);

            foreach ($lines as $line) {
                $item = $order->items()->create([
                    'product_id' => $line['variant']->product_id,
                    'product_variant_id' => $line['variant']->id,
                    'product_name' => $line['variant']->product->name,
                    'variant_name' => $line['variant']->name,
                    'unit_price' => $line['variant']->price,
                    'addons_total' => $line['addonsTotal'],
                    'quantity' => $line['qty'],
                    'line_total' => $line['lineTotal'],
                    'notes' => $line['notes'],
                ]);
                foreach ($line['addons'] as $addon) {
                    /** @var Addon $addon */
                    $item->addons()->create(['addon_id' => $addon->id, 'addon_name' => $addon->name, 'price' => $addon->price]);
                }

                // Sales ↔ Inventory: which items this line uses (counted later, when marked Ready).
                foreach ($this->recipes->forOneDrink($line['variant']->product, $line['addons']) as $itemId => $servings) {
                    $item->ingredients()->create([
                        'inventory_item_id' => $itemId,
                        'servings' => $servings * $line['qty'],
                        'created_at' => $at,
                    ]);
                }
            }

            AuditLogger::log('sales', 'order_placed',
                "Placed order {$order->order_number} (Queue #{$queueNumber}) — ".peso($order->total_amount),
                $order, null, ['total' => $order->total_amount, 'type' => $order->order_type, 'items' => count($lines)], $cashier);

            if ($payment) {
                $this->recordPayment($order, $payment, $cashier, $at);
            }

            return $order->fresh(['items.addons', 'payment']);
        });
    }

    /**
     * @param  array{method:string, amount_tendered?:?float, provider?:?string, reference_number?:?string}  $data
     */
    public function recordPayment(Order $order, array $data, ?User $by = null, ?Carbon $at = null): Payment
    {
        $by ??= Auth::user();
        $at ??= now();

        if ($order->isPaid()) {
            throw new BusinessRuleException("Order {$order->order_number} is already paid.");
        }
        if (in_array($order->status, [Order::STATUS_CANCELLED, Order::STATUS_REFUNDED])) {
            throw new BusinessRuleException('A cancelled or refunded order cannot be paid.');
        }
        $shift = CashShift::current();
        if (! $shift) {
            throw new BusinessRuleException('Open the cash drawer (start a shift) before accepting payments.');
        }

        $method = $data['method'];
        $total = (float) $order->total_amount;
        $tendered = null;
        $change = 0.0;

        if ($method === 'cash') {
            $tendered = round((float) ($data['amount_tendered'] ?? 0), 2);
            if ($tendered < $total) {
                throw new BusinessRuleException('Cash received ('.peso($tendered).') is less than the amount due ('.peso($total).').');
            }
            $change = round($tendered - $total, 2);
        } elseif (blank($data['reference_number'] ?? null)) {
            throw new BusinessRuleException('Enter the transaction reference number for card / online payments.');
        }

        return DB::transaction(function () use ($order, $data, $by, $at, $shift, $method, $total, $tendered, $change) {
            // Re-check under a row lock: two cashiers (or a double click) must not pay the same order twice.
            $locked = Order::lockForUpdate()->findOrFail($order->id);
            if ($locked->isPaid() || in_array($locked->status, [Order::STATUS_CANCELLED, Order::STATUS_REFUNDED])) {
                throw new BusinessRuleException("Order {$order->order_number} was just updated by someone else. Refresh and check it.");
            }

            $payment = $order->payments()->create([
                'received_by' => $by->id,
                'cash_shift_id' => $shift->id,
                'method' => $method,
                'provider' => $method === 'cash' ? null : ($data['provider'] ?? null),
                'reference_number' => $method === 'cash' ? null : $data['reference_number'],
                'amount' => $total,
                'amount_tendered' => $tendered,
                'change_amount' => $change,
                'created_at' => $at,
                'updated_at' => $at,
            ]);

            $order->forceFill(['payment_status' => 'paid', 'updated_at' => $at])->save();

            AuditLogger::log('sales', 'payment_received',
                "Received {$payment->methodLabel()} payment of ".peso($total)." for {$order->order_number}",
                $order, null, ['method' => $method, 'provider' => $payment->provider, 'reference' => $payment->reference_number, 'amount' => $total], $by);

            return $payment;
        });
    }

    public function markReady(Order $order, ?User $by = null, ?Carbon $at = null): Order
    {
        if (! $order->canMarkReady()) {
            throw new BusinessRuleException("Only pending orders can be marked ready.");
        }
        $at ??= now();
        $this->notices = [];

        return DB::transaction(function () use ($order, $by, $at) {
            $order->forceFill(['status' => Order::STATUS_READY, 'ready_at' => $at, 'updated_at' => $at])->save();
            AuditLogger::log('sales', 'order_ready', "Marked order {$order->order_number} as ready", $order,
                ['status' => 'pending'], ['status' => 'ready'], $by);

            // The drink is made now: count it once against the open units it used.
            $this->notices = $this->servings->countOrder($order, $by, $at);

            return $order;
        });
    }

    public function complete(Order $order, ?User $by = null, ?Carbon $at = null): Order
    {
        if ($order->status !== Order::STATUS_READY) {
            throw new BusinessRuleException('Only orders that are ready can be completed.');
        }
        if (! $order->isPaid()) {
            throw new BusinessRuleException('Collect payment before completing this order.');
        }
        $at ??= now();
        $order->forceFill(['status' => Order::STATUS_COMPLETED, 'completed_at' => $at, 'updated_at' => $at])->save();
        AuditLogger::log('sales', 'order_completed', "Completed order {$order->order_number} (served to customer)", $order,
            ['status' => 'ready'], ['status' => 'completed'], $by);

        return $order;
    }

    public function cancel(Order $order, string $reason, ?User $by = null): Order
    {
        if (! $order->canCancel()) {
            throw new BusinessRuleException($order->isPaid()
                ? 'This order is already paid — process a refund instead.'
                : 'Only pending or ready orders can be cancelled.');
        }
        $old = $order->status;
        $order->forceFill(['status' => Order::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();
        AuditLogger::log('sales', 'cancelled', "Cancelled order {$order->order_number}: {$reason}", $order,
            ['status' => $old], ['status' => 'cancelled', 'reason' => $reason], $by);

        return $order;
    }

    /**
     * Full refund of a paid order. Requires a manager's PIN (approver).
     */
    public function refund(Order $order, string $reason, User $approver, ?User $by = null, ?Carbon $at = null): Refund
    {
        $by ??= Auth::user();
        $at ??= now();

        if (! $approver->isManager()) {
            throw new BusinessRuleException('Refunds must be approved by a manager.');
        }
        if (! $order->canRefund()) {
            throw new BusinessRuleException('Only paid orders that have not been refunded can be refunded.');
        }
        $payment = $order->payment;
        $shift = CashShift::current();
        if ($payment?->method === 'cash' && ! $shift) {
            throw new BusinessRuleException('Open the cash drawer before giving a cash refund.');
        }

        if ((float) $order->total_amount <= 0) {
            throw new BusinessRuleException('This order was free (₱0.00), so there is nothing to refund.');
        }

        return DB::transaction(function () use ($order, $reason, $approver, $by, $at, $payment, $shift) {
            // Re-check under a row lock so the same order can never be refunded twice.
            $locked = Order::lockForUpdate()->findOrFail($order->id);
            if (! $locked->canRefund()) {
                throw new BusinessRuleException('This order has already been refunded.');
            }

            $refund = Refund::create([
                'order_id' => $order->id,
                'payment_id' => $payment?->id,
                'cash_shift_id' => $shift?->id,
                'processed_by' => $by->id,
                'approved_by' => $approver->id,
                'method' => $payment?->method ?? 'cash',
                'amount' => $order->total_amount,
                'reason' => $reason,
                'created_at' => $at,
                'updated_at' => $at,
            ]);

            $old = $order->status;
            $order->forceFill(['status' => Order::STATUS_REFUNDED, 'payment_status' => 'refunded', 'updated_at' => $at])->save();

            AuditLogger::log('sales', 'refund',
                "Refunded ".peso($refund->amount)." for {$order->order_number} (approved by {$approver->name}): {$reason}",
                $order, ['status' => $old], ['status' => 'refunded', 'amount' => $refund->amount, 'approved_by' => $approver->name, 'reason' => $reason], $by);

            return $refund;
        });
    }

    /**
     * Next order number (ORD-YYYYMMDD-0001) and daily queue number.
     * A transaction-scoped advisory lock prevents two cashiers from getting the same number.
     */
    private function nextNumbers(Carbon $at): array
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(?)', [7301]);
        }

        $count = Order::withTrashed()
            ->whereBetween('created_at', [$at->copy()->startOfDay(), $at->copy()->endOfDay()])
            ->count();
        $seq = $count + 1;

        return ['ORD-'.$at->format('Ymd').'-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT), $seq];
    }
}
