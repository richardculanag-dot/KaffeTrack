<?php

namespace Database\Seeders;

use App\Models\Discount;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Income;
use App\Models\InventoryItem;
use App\Models\ItemUsage;
use App\Models\ProductVariant;
use App\Models\StockBatch;
use App\Models\Supplier;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\OrderPricing;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Generates realistic, deterministic demo history so the dashboard, finance and reports
 * have meaningful data on day one.
 *
 *  • Sales (default 90 days): cash shifts, orders, items, add-ons, payments, a few cancellations
 *    and manager-approved refunds — written with BULK inserts so seeding a remote Supabase
 *    database takes seconds instead of many minutes.
 *  • Inventory (default 21 days): goes through the real InventoryService (deliveries, stock outs,
 *    depletions), so the ledger, batches and on-hand counts are guaranteed consistent.
 *  • Finance: monthly rent, payroll, utilities and other operating costs + a few other incomes.
 *
 * History ends YESTERDAY; today starts clean with no open shift, so the first thing a
 * demo shows is opening the cash drawer.
 *
 * Tunables (.env): SEED_DEMO_DAYS=90, SEED_INVENTORY_DAYS=21
 */
class DemoHistorySeeder extends Seeder
{
    private array $auditRows = [];

    public function run(): void
    {
        mt_srand(20261010); // deterministic: the same demo data every time
        $clock = Carbon::getTestNow(); // normally null (real time); kept so a fixed test date stays fixed

        $days = (int) env('SEED_DEMO_DAYS', 90);
        $invDays = min($days, (int) env('SEED_INVENTORY_DAYS', 21));

        $start = today()->subDays($days);
        $managers = User::where('role', 'manager')->orderBy('id')->get();
        $staff = User::where('role', 'staff')->orderBy('id')->get();

        $this->command?->info("Generating {$days} days of sales…");
        $this->seedSales($start, $days, $managers, $staff);

        $this->command?->info("Simulating {$invDays} days of inventory activity…");
        $this->seedInventory(today()->subDays($invDays), $invDays, $managers->first(), $staff);

        $this->command?->info('Recording operating expenses and other income…');
        $this->seedFinance($start, $days, $invDays, $managers->first());

        $this->flushAudit();
        $this->resetSequences();
        Carbon::setTestNow($clock);
    }

    /* ───────────────────────────────── Sales ───────────────────────────────── */

    private function seedSales(Carbon $start, int $days, $managers, $staff): void
    {
        $variants = ProductVariant::with('product.addons', 'product.category')->get()
            ->filter(fn ($v) => $v->product)->values();

        // Popularity weights per product (bigger = ordered more often).
        $popularity = [
            'Spanish Latte' => 14, 'Café Latte' => 12, 'Americano' => 11, 'Caramel Macchiato' => 10,
            'Java Chip Frappé' => 8, 'Matcha Latte' => 8, 'Mocha' => 7, 'Cappuccino' => 6, 'Caramel Frappé' => 6,
            'Calamansi Iced Tea' => 6, 'Butter Croissant' => 7, 'Ensaymada' => 5, 'Banana Bread' => 5,
            'Dark Chocolate' => 4, 'Matcha Cream Frappé' => 4, 'Chocolate Chip Cookie' => 5,
            'Blueberry Cheesecake' => 3, 'Strawberry Milk' => 3, 'Davao Pour-Over' => 2, 'Chamomile' => 2,
        ];
        $pool = [];
        foreach ($variants as $v) {
            $w = $popularity[$v->product->name] ?? 2;
            for ($i = 0; $i < $w; $i++) {
                $pool[] = $v;
            }
        }

        $discounts = Discount::all()->keyBy('name');

        $ids = [
            'shift' => (int) DB::table('cash_shifts')->max('id'),
            'order' => (int) DB::table('orders')->max('id'),
            'item' => (int) DB::table('order_items')->max('id'),
            'payment' => (int) DB::table('payments')->max('id'),
        ];

        for ($d = 0; $d < $days; $d++) {
            $day = $start->copy()->addDays($d);
            $cashier = $staff[$d % $staff->count()];
            $manager = $managers[$d % $managers->count()];

            $shiftId = ++$ids['shift'];
            $opened = $day->copy()->setTime(6, 45);
            $closed = $day->copy()->setTime(21, 10 + mt_rand(0, 20));

            $orders = $items = $itemAddons = $payments = $refunds = [];
            $cashSales = $cashRefunds = 0.0;

            // Volume: weekends busier, paydays (15th / 30th) busier, gentle upward trend.
            $base = $day->isWeekend() ? 46 : 32;
            if (in_array($day->day, [15, 16, 30, 31])) {
                $base += 10;
            }
            $count = (int) round(($base + mt_rand(-6, 6)) * (1 + $d * 0.002));

            $times = [];
            for ($i = 0; $i < $count; $i++) {
                $times[] = $day->copy()->setTime($this->weightedHour(), mt_rand(0, 59), mt_rand(0, 59));
            }
            sort($times);

            foreach ($times as $q => $at) {
                $orderId = ++$ids['order'];
                $lineCount = $this->pick([1 => 55, 2 => 32, 3 => 13]);
                $subtotal = 0.0;
                $orderItems = [];

                for ($l = 0; $l < $lineCount; $l++) {
                    $v = $pool[mt_rand(0, count($pool) - 1)];
                    $qty = mt_rand(1, 100) <= 85 ? 1 : 2;
                    $addons = [];
                    if ($v->product->addons->isNotEmpty() && mt_rand(1, 100) <= 22) {
                        $addons[] = $v->product->addons->random();
                    }
                    $addonsTotal = array_sum(array_map(fn ($a) => (float) $a->price, $addons));
                    $lineTotal = round(((float) $v->price + $addonsTotal) * $qty, 2);
                    $subtotal += $lineTotal;

                    $itemId = ++$ids['item'];
                    $orderItems[] = [
                        'id' => $itemId, 'order_id' => $orderId, 'product_id' => $v->product_id, 'product_variant_id' => $v->id,
                        'product_name' => $v->product->name, 'variant_name' => $v->name, 'unit_price' => $v->price,
                        'addons_total' => $addonsTotal, 'quantity' => $qty, 'line_total' => $lineTotal,
                        'notes' => mt_rand(1, 100) <= 8 ? collect(['Less ice', 'Less sweet', 'Extra hot', 'No sugar'])->random() : null,
                        'created_at' => $at, 'updated_at' => $at,
                    ];
                    foreach ($addons as $a) {
                        $itemAddons[] = ['order_item_id' => $itemId, 'addon_id' => $a->id, 'addon_name' => $a->name, 'price' => $a->price];
                    }
                }

                $roll = mt_rand(1, 100);
                $discount = match (true) {
                    $roll <= 4 => $discounts['Senior Citizen'],
                    $roll <= 6 => $discounts['PWD'],
                    $roll <= 9 => $discounts['Employee'],
                    $roll <= 11 => $discounts['Loyalty ₱20 Off'],
                    default => null,
                };
                $t = OrderPricing::compute($subtotal, $discount, true, 12);

                $outcome = mt_rand(1, 1000);
                $status = $outcome <= 15 ? 'cancelled' : ($outcome <= 20 ? 'refunded' : 'completed');
                $type = mt_rand(1, 100) <= 58 ? 'takeout' : 'dine_in';
                $readyAt = $at->copy()->addMinutes(mt_rand(3, 9));
                $completedAt = $readyAt->copy()->addMinutes(mt_rand(1, 4));
                $orderNumber = 'ORD-'.$at->format('Ymd').'-'.str_pad((string) ($q + 1), 4, '0', STR_PAD_LEFT);

                $orders[] = [
                    'id' => $orderId, 'order_number' => $orderNumber, 'queue_number' => $q + 1,
                    'user_id' => $cashier->id, 'cash_shift_id' => $shiftId, 'order_type' => $type,
                    'customer_name' => mt_rand(1, 100) <= 45 ? $this->customerName() : null,
                    'table_number' => $type === 'dine_in' ? (string) mt_rand(1, 12) : null,
                    'status' => $status,
                    'payment_status' => $status === 'cancelled' ? 'unpaid' : ($status === 'refunded' ? 'refunded' : 'paid'),
                    'subtotal' => $t['subtotal'], 'discount_id' => $discount?->id, 'discount_name' => $discount?->name,
                    'discount_id_number' => $discount?->requires_id ? 'ID-'.mt_rand(100000, 999999) : null,
                    'discount_amount' => $t['discount_amount'], 'vatable_sales' => $t['vatable_sales'],
                    'vat_amount' => $t['vat_amount'], 'vat_exempt_sales' => $t['vat_exempt_sales'], 'total_amount' => $t['total_amount'],
                    'notes' => null,
                    'ready_at' => $status === 'cancelled' ? null : $readyAt,
                    'completed_at' => $status === 'cancelled' ? null : $completedAt,
                    'cancelled_at' => $status === 'cancelled' ? $at->copy()->addMinutes(2) : null,
                    'cancel_reason' => $status === 'cancelled' ? collect(['Customer changed mind', 'Wrong order entered', 'Customer left'])->random() : null,
                    'created_at' => $at, 'updated_at' => $completedAt,
                ];
                $items = array_merge($items, $orderItems);

                $this->audit($at, $cashier, 'sales', 'order_placed', "Placed order {$orderNumber} (Queue #".($q + 1).') — '.peso($t['total_amount']), 'order', $orderId);

                if ($status === 'cancelled') {
                    $this->audit($at->copy()->addMinutes(2), $cashier, 'sales', 'cancelled', "Cancelled order {$orderNumber}", 'order', $orderId);

                    continue;
                }

                // Payment
                $m = mt_rand(1, 100);
                $method = $m <= 55 ? 'cash' : ($m <= 85 ? 'online' : 'card');
                $total = (float) $t['total_amount'];
                $tendered = $method === 'cash' ? $this->tender($total) : null;
                $paymentId = ++$ids['payment'];
                $payments[] = [
                    'id' => $paymentId, 'order_id' => $orderId, 'received_by' => $cashier->id, 'cash_shift_id' => $shiftId,
                    'method' => $method,
                    'provider' => $method === 'online' ? (mt_rand(1, 100) <= 80 ? 'GCash' : 'Maya') : ($method === 'card' ? (mt_rand(1, 2) === 1 ? 'Visa' : 'Mastercard') : null),
                    'reference_number' => $method === 'cash' ? null : strtoupper(substr(md5($orderNumber), 0, 12)),
                    'amount' => $total, 'amount_tendered' => $tendered, 'change_amount' => $tendered ? round($tendered - $total, 2) : 0,
                    'created_at' => $at, 'updated_at' => $at,
                ];
                if ($method === 'cash') {
                    $cashSales += $total;
                }

                $this->audit($at, $cashier, 'sales', 'payment_received', 'Received '.ucfirst($method).' payment of '.peso($total)." for {$orderNumber}", 'order', $orderId);
                $this->audit($readyAt, $cashier, 'sales', 'order_ready', "Marked order {$orderNumber} as ready", 'order', $orderId);
                $this->audit($completedAt, $cashier, 'sales', 'order_completed', "Completed order {$orderNumber} (served to customer)", 'order', $orderId);

                if ($status === 'refunded') {
                    $refundAt = $completedAt->copy()->addMinutes(mt_rand(5, 25));
                    $reason = collect(['Drink spilled / remade not possible', 'Wrong drink served', 'Customer complaint — quality'])->random();
                    $refunds[] = [
                        'order_id' => $orderId, 'payment_id' => $paymentId, 'cash_shift_id' => $shiftId,
                        'processed_by' => $cashier->id, 'approved_by' => $manager->id, 'method' => $method,
                        'amount' => $total, 'reason' => $reason, 'created_at' => $refundAt, 'updated_at' => $refundAt,
                    ];
                    if ($method === 'cash') {
                        $cashRefunds += $total;
                    }
                    $this->audit($refundAt, $cashier, 'sales', 'refund', 'Refunded '.peso($total)." for {$orderNumber} (approved by {$manager->name}): {$reason}", 'order', $orderId);
                }
            }

            // Cash drawer: realistic small over/short at end of day.
            $opening = 3000.00;
            $cashOut = $d % 6 === 0 ? 350.00 : 0.0;
            $expected = round($opening + $cashSales - $cashRefunds - $cashOut, 2);
            $varianceRoll = mt_rand(1, 100);
            $variance = $varianceRoll <= 70 ? 0.0 : ($varianceRoll <= 85 ? -1 * mt_rand(5, 60) : mt_rand(5, 40));
            $counted = round($expected + $variance, 2);

            DB::table('cash_shifts')->insert([
                'id' => $shiftId, 'opened_by' => $cashier->id, 'opened_at' => $opened, 'opening_cash' => $opening,
                'closed_by' => $cashier->id, 'closed_at' => $closed, 'expected_cash' => $expected, 'counted_cash' => $counted,
                'variance' => $variance, 'denominations' => json_encode($this->breakdown($counted)), 'status' => 'closed',
                'notes' => $variance < 0 ? 'Short — recounted twice.' : null,
                'created_at' => $opened, 'updated_at' => $closed,
            ]);
            if ($cashOut > 0) {
                DB::table('cash_movements')->insert([
                    'cash_shift_id' => $shiftId, 'user_id' => $cashier->id, 'type' => 'cash_out', 'amount' => $cashOut,
                    'reason' => 'Bought ice and calamansi from market', 'created_at' => $day->copy()->setTime(10, 15), 'updated_at' => $day->copy()->setTime(10, 15),
                ]);
            }

            foreach (array_chunk($orders, 300) as $chunk) {
                DB::table('orders')->insert($chunk);
            }
            foreach (array_chunk($items, 400) as $chunk) {
                DB::table('order_items')->insert($chunk);
            }
            foreach (array_chunk($itemAddons, 500) as $chunk) {
                DB::table('order_item_addons')->insert($chunk);
            }
            foreach (array_chunk($payments, 400) as $chunk) {
                DB::table('payments')->insert($chunk);
            }
            if ($refunds) {
                DB::table('refunds')->insert($refunds);
            }

            $this->audit($opened->copy()->subMinutes(5), $cashier, 'auth', 'login', "{$cashier->name} logged in", 'user', $cashier->id);
            $this->audit($opened, $cashier, 'sales', 'shift_opened', 'Opened cash drawer with '.peso($opening).' starting cash', 'cash_shift', $shiftId);
            $this->audit($closed, $cashier, 'sales', 'shift_closed', 'Closed cash drawer — expected '.peso($expected).', counted '.peso($counted), 'cash_shift', $shiftId);
            $this->audit($closed->copy()->addMinutes(3), $cashier, 'auth', 'logout', "{$cashier->name} logged out", 'user', $cashier->id);

            if (count($this->auditRows) > 2000) {
                $this->flushAudit();
            }
        }
    }

    /* ─────────────────────────────── Inventory ─────────────────────────────── */

    private function seedInventory(Carbon $start, int $days, User $manager, $staff): void
    {
        $service = app(InventoryService::class);
        $today = Carbon::today(); // seeding day (the loop below moves the test clock)
        $clock = Carbon::getTestNow(); // restored at the end
        $items = InventoryItem::all()->keyBy('sku');
        $suppliers = Supplier::all()->keyBy('id');

        // [unit cost, target stock after delivery, days one opened unit lasts]
        $profile = [
            'BN-HOUSE' => [1150, 8, 2.0], 'BN-APO' => [780, 5, 4.0], 'DY-MILK' => [98, 30, 0.35],
            'DY-OAT' => [210, 10, 1.6], 'DY-ALM' => [220, 8, 2.5], 'DY-CON' => [72, 10, 1.4],
            'DY-CRM' => [330, 5, 3.5], 'SY-VAN' => [520, 5, 5.0], 'SY-CAR' => [610, 5, 5.0],
            'SY-CHO' => [590, 5, 4.5], 'PW-MAT' => [890, 5, 5.0], 'PW-FRP' => [640, 5, 4.0],
            'PK-CUP16' => [260, 12, 1.4], 'PK-CUP12' => [240, 10, 2.2], 'PK-STR' => [180, 5, 5.5],
            'BK-CRO' => [1080, 4, 3.0],
        ];
        $shelfLife = ['BN' => 120, 'DY' => 14, 'SY' => 240, 'PW' => 180, 'BK' => 30];
        // Per-item shelf life where the category default is unrealistic: only fresh milk lasts ~2 weeks;
        // UHT cream, canned condensed milk and boxed plant milks keep for months. (Keeps slow movers like
        // whipping cream from showing "Expired" in the demo.)
        $shelfLifeBySku = ['DY-CRM' => 60, 'DY-CON' => 365, 'DY-OAT' => 180, 'DY-ALM' => 180];

        $deliver = function (Carbon $date, bool $initial) use ($service, $items, $suppliers, $profile, $shelfLife, $shelfLifeBySku, $manager) {
            $bySupplier = [];
            $onHand = InventoryItem::pluck('quantity_on_hand', 'id');
            foreach ($items as $sku => $item) {
                [$cost, $target] = $profile[$sku];
                $need = $target - $onHand[$item->id];
                if (! $initial && $onHand[$item->id] > $item->reorder_level + 1) {
                    continue;
                }
                if ($need <= 0) {
                    continue;
                }
                $prefix = explode('-', $sku)[0];
                $bySupplier[$item->default_supplier_id][] = [
                    'inventory_item_id' => $item->id,
                    'quantity' => $need,
                    'unit_cost' => $cost,
                    'expiry_date' => $item->track_expiry ? $date->copy()->addDays($shelfLifeBySku[$sku] ?? $shelfLife[$prefix] ?? 60)->toDateString() : null,
                    'batch_code' => $prefix.$date->format('md'),
                ];
            }
            foreach ($bySupplier as $supplierId => $lines) {
                // Deliveries arrive before opening (6:00–6:40), so the ledger stays in time order:
                // every balance follows from the line before it.
                Carbon::setTestNow($date->copy()->setTime(6, mt_rand(0, 40)));
                $service->stockIn($suppliers[$supplierId], $lines, $date, 'INV-'.mt_rand(10000, 99999), null, true,
                    collect(['cash', 'bank_transfer'])->random(), $manager);
            }
        };

        // Opening balance.
        $deliver($start->copy(), true);

        $nextOpen = [];
        foreach ($profile as $sku => $p) {
            $nextOpen[$sku] = $start->copy()->setTime(7, 0)->addMinutes(mt_rand(0, 600));
        }

        // Manager actions on specific days — run in time order together with that day's stock-outs.
        $special = [
            // Delivered yesterday afternoon so they are still in storage today (earlier, first-expired-first-out
            // would already have used them up).
            $today->copy()->subDay()->setTime(16, 45)->toDateTimeString() => function () use ($service, $items, $manager, $today) {
                // Demo alerts: two short-dated batches that show under "Expiring ≤ 7 days" (none already expired).
                $service->stockIn(Supplier::where('name', 'Mindanao Dairy Co-op')->first(), [
                    ['inventory_item_id' => $items['DY-CRM']->id, 'quantity' => 2, 'unit_cost' => 330, 'expiry_date' => $today->copy()->addDays(5)->toDateString(), 'batch_code' => 'CRM-PROMO'],
                    ['inventory_item_id' => $items['DY-MILK']->id, 'quantity' => 6, 'unit_cost' => 98, 'expiry_date' => $today->copy()->addDays(3)->toDateString(), 'batch_code' => 'MLK-SHORT'],
                ], $today->copy()->subDay(), 'INV-55120', 'Promo batch — short dated', true, 'cash', $manager);
            },
            // A couple of manager write-offs so the movement ledger shows every type.
            $today->copy()->subDays(3)->setTime(16, 20)->toDateTimeString() => fn () => $service->adjust($items['DY-MILK']->refresh(), 'waste', 1, 'Carton dropped and burst during unloading', null, $manager),
            $today->copy()->subDays(2)->setTime(18, 5)->toDateTimeString() => fn () => $service->adjust($items['PK-STR']->refresh(), 'adjustment_in', 1, 'Physical count found an extra box in storage', null, $manager),
            // Slow movers finished last night and not reopened yet, so the demo shows items with nothing
            // open at the bar (Stock out button) next to items that are in use (Mark depleted).
            $today->copy()->subDay()->setTime(21, 5)->toDateTimeString() => function () use ($service, $items, $staff) {
                foreach (['DY-ALM', 'BN-APO', 'BK-CRO', 'SY-VAN', 'PK-STR'] as $sku) {
                    $open = ItemUsage::where('inventory_item_id', $items[$sku]->id)->where('status', 'in_use')->first();
                    if ($open) {
                        $service->markDepleted($open, null, $staff->first(), Carbon::now());
                    }
                }
            },
            // Make sure the dashboard has low-stock items to show: run two items down.
            $today->copy()->subDay()->setTime(17, 30)->toDateTimeString() => function () use ($service, $items, $manager) {
                foreach (['SY-CAR', 'PW-MAT'] as $sku) {
                    $item = $items[$sku]->refresh();
                    $remove = max(0, $item->quantity_on_hand - 1);
                    if ($remove > 0) {
                        $service->adjust($item, 'adjustment_out', $remove, 'Stock count correction (moved to sister branch)', null, $manager);
                    }
                }
            },
        ];

        for ($d = 0; $d < $days; $d++) {
            $day = $start->copy()->addDays($d);

            // Twice-weekly deliveries (Mon & Thu) for anything at or near its reorder level.
            if ($d > 0 && in_array($day->dayOfWeek, [Carbon::MONDAY, Carbon::THURSDAY])) {
                $deliver($day, false);
            }

            // Plan the day's openings, then run them with any manager action strictly in time order.
            $events = [];
            $dayEnd = $day->copy()->setTime(21, 0);
            foreach ($profile as $sku => [$cost, $target, $lasts]) {
                while ($nextOpen[$sku]->lt($dayEnd)) {
                    $at = $nextOpen[$sku]->copy();
                    if ($at->hour < 7) {
                        $at->setTime(7, mt_rand(0, 30));
                    }
                    $person = $staff[mt_rand(0, $staff->count() - 1)];
                    $item = $items[$sku];

                    $events[] = [$at->copy(), function () use ($service, $item, $person, $at) {
                        $open = ItemUsage::where('inventory_item_id', $item->id)->where('status', 'in_use')->first();
                        if ($open) {
                            Carbon::setTestNow($at);
                            $service->markDepleted($open, null, $person, $at);
                        }
                        if (InventoryItem::whereKey($item->id)->value('quantity_on_hand') > 0) {
                            Carbon::setTestNow($at->copy()->addMinute());
                            $service->stockOut($item, null, $person, $at->copy()->addMinute());
                        }
                    }];

                    // Next unit opens when this one runs out (± randomness, staff are human).
                    $hours = max(2, $lasts * 14 * (0.75 + mt_rand(0, 50) / 100));
                    $nextOpen[$sku] = $at->copy()->addMinutes((int) ($hours * 60));
                    if ($nextOpen[$sku]->hour >= 21) {
                        $nextOpen[$sku]->addDay()->setTime(7, mt_rand(0, 59));
                    }
                }
            }
            foreach ($special as $when => $action) {
                $whenAt = Carbon::parse($when);
                if ($whenAt->isSameDay($day)) {
                    $events[] = [$whenAt, function () use ($whenAt, $action) {
                        Carbon::setTestNow($whenAt);
                        $action();
                    }];
                }
            }

            usort($events, fn ($a, $b) => $a[0] <=> $b[0]);
            foreach ($events as [, $run]) {
                $run();
            }
        }

        Carbon::setTestNow($clock);
    }

    /* ──────────────────────────────── Finance ──────────────────────────────── */

    private function seedFinance(Carbon $start, int $days, int $invDays, User $manager): void
    {
        Expense::$auditingEnabled = Income::$auditingEnabled = false;
        $cat = ExpenseCategory::pluck('id', 'name');
        $rows = [];

        $add = function (string $category, string $desc, float $amount, Carbon $date, string $method = 'bank_transfer') use (&$rows, $cat, $manager) {
            if ($date->gte(today())) {
                return;
            }
            $ts = $date->copy()->setTime(11, 0);
            $rows[] = [
                'expense_category_id' => $cat[$category], 'supplier_id' => null, 'stock_in_id' => null, 'recorded_by' => $manager->id,
                'description' => $desc, 'amount' => round($amount, 2), 'expense_date' => $date->toDateString(),
                'payment_method' => $method, 'reference_number' => null, 'notes' => null, 'created_at' => $ts, 'updated_at' => $ts,
            ];
        };

        for ($d = 0; $d < $days; $d++) {
            $day = $start->copy()->addDays($d);
            $month = $day->format('F Y');

            if ($day->day === 1) {
                $add('Rent', "Store lease — {$month}", 35000, $day);
                $add('Marketing', "Facebook & Instagram ads — {$month}", mt_rand(2500, 4500), $day, 'card');
            }
            if ($day->day === 5) {
                $add('Utilities', "Davao Light electricity — {$month}", mt_rand(8200, 11800), $day);
                $add('Utilities', "Davao City Water District — {$month}", mt_rand(900, 1500), $day);
                $add('Utilities', "Fiber internet — {$month}", 1899, $day, 'online');
            }
            if (in_array($day->day, [15, $day->copy()->endOfMonth()->day])) {
                $add('Salaries & Wages', 'Staff payroll (3 baristas, semi-monthly)', 27600, $day);
            }
            if ($day->dayOfWeek === Carbon::SATURDAY) {
                $add('Supplies', 'Cleaning supplies, tissue and sanitizer', mt_rand(450, 1100), $day, 'cash');
            }
            if ($day->day === 20 && $d % 2 === 0) {
                $add('Maintenance & Repairs', 'Espresso machine preventive maintenance', 3500, $day);
            }
            // Before the inventory simulation window: purchases recorded manually (pre-system).
            if ($d < $days - $invDays && in_array($day->dayOfWeek, [Carbon::MONDAY, Carbon::THURSDAY])) {
                $add(ExpenseCategory::INVENTORY_PURCHASES, 'Ingredient & packaging purchases (manual record)', mt_rand(9000, 15000), $day, 'cash');
            }
        }
        foreach (array_chunk($rows, 300) as $chunk) {
            DB::table('expenses')->insert($chunk);
        }

        $incomes = [
            ['Catering — UM Seminar coffee bar', 18500, 40],
            ['Event booth — Kadayawan pop-up', 24300, 58],
            ['Corporate order — 120 cups (Bajada office)', 15600, 21],
            ['Sale of used coffee grounds (garden supplier)', 600, 9],
        ];
        foreach ($incomes as [$source, $amount, $ago]) {
            if ($ago >= $days) {
                continue;
            }
            $date = today()->subDays($ago);
            DB::table('incomes')->insert([
                'recorded_by' => $manager->id, 'source' => $source, 'amount' => $amount, 'income_date' => $date->toDateString(),
                'payment_method' => 'bank_transfer', 'reference_number' => null, 'notes' => null,
                'created_at' => $date->copy()->setTime(15, 0), 'updated_at' => $date->copy()->setTime(15, 0),
            ]);
        }
        Expense::$auditingEnabled = Income::$auditingEnabled = true;
    }

    /* ──────────────────────────────── Helpers ──────────────────────────────── */

    private function audit(Carbon $at, User $user, string $module, string $event, string $description, ?string $type = null, ?int $id = null): void
    {
        $this->auditRows[] = [
            'user_id' => $user->id, 'user_name' => $user->name, 'module' => $module, 'event' => $event,
            'auditable_type' => $type, 'auditable_id' => $id, 'description' => $description,
            'old_values' => null, 'new_values' => null, 'ip_address' => '192.168.1.'.(20 + $user->id),
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/141.0', 'created_at' => $at,
        ];
    }

    private function flushAudit(): void
    {
        foreach (array_chunk($this->auditRows, 500) as $chunk) {
            DB::table('audit_logs')->insert($chunk);
        }
        $this->auditRows = [];
    }

    /** Explicit IDs were used for bulk inserts — move each sequence past the highest id. */
    private function resetSequences(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach (['cash_shifts', 'orders', 'order_items', 'payments'] as $table) {
            DB::statement("SELECT setval(pg_get_serial_sequence('{$table}', 'id'), COALESCE((SELECT MAX(id) FROM {$table}), 1))");
        }
    }

    private function weightedHour(): int
    {
        // Morning rush 7–10, lunch 12–13, afternoon merienda 15–17, evening tail.
        return $this->pick([7 => 9, 8 => 12, 9 => 10, 10 => 7, 11 => 6, 12 => 7, 13 => 7, 14 => 6, 15 => 9, 16 => 9, 17 => 7, 18 => 5, 19 => 4, 20 => 2]);
    }

    private function pick(array $weights): int
    {
        $r = mt_rand(1, array_sum($weights));
        foreach ($weights as $value => $w) {
            if (($r -= $w) <= 0) {
                return $value;
            }
        }

        return array_key_first($weights);
    }

    private function tender(float $total): float
    {
        $options = array_filter([ceil($total), ceil($total / 50) * 50, ceil($total / 100) * 100, ceil($total / 500) * 500, 1000], fn ($v) => $v >= $total);

        return (float) $options[array_rand($options)];
    }

    private function breakdown(float $amount): array
    {
        $out = [];
        $left = (int) round($amount);
        foreach ([1000, 500, 200, 100, 50, 20, 10, 5, 1] as $d) {
            if ($n = intdiv($left, $d)) {
                $out[(string) $d] = $n;
                $left -= $n * $d;
            }
        }

        return $out;
    }

    private function customerName(): string
    {
        return collect(['Joy', 'Mark', 'Angel', 'Paolo', 'Kim', 'Rica', 'Jun', 'Trish', 'Nico', 'Aira', 'Bryan', 'Liza', 'Ken', 'Mae', 'Gab', 'Lea'])->random();
    }
}
