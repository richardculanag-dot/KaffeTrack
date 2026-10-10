<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\ItemUsage;
use App\Models\Order;
use App\Models\OrderItemIngredient;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Counts drinks against the unit that is open when they are made, and warns before a unit runs out.
 *
 *  - Counting happens ONCE per order line, when the order is marked Ready (the drink is made then).
 *    Orders cancelled or refunded before Ready are never counted; after Ready they stay counted,
 *    because the ingredient was really used.
 *  - Three one-time stages per open unit: alert (e.g. 80 % of what a unit usually serves),
 *    reached (100 %), flagged (150 % — something was probably not recorded; a manager must review).
 *  - It is an ESTIMATE. It never changes quantity_on_hand or the stock ledger, never closes a unit
 *    and never blocks a sale. Staff confirm by checking the actual container.
 */
class ServingTracker
{
    /** Average over this many recent used-up units… */
    public const HISTORY_SIZE = 5;

    /** …once at least this many exist; until then the manager's starting guess is used. */
    public const MIN_HISTORY = 3;

    /** A unit this far past its expectation becomes a manager exception. */
    public const FLAG_PERCENT = 150;

    /**
     * Count the order's uncounted lines against the open units. Call inside the Ready transaction.
     *
     * @return array<int, array{type:string, message:string}>  notices to show the person who marked it ready
     */
    public function countOrder(Order $order, ?User $by = null, ?Carbon $at = null): array
    {
        $by ??= Auth::user();
        $at ??= now();
        $notices = [];

        $rows = OrderItemIngredient::query()
            ->whereIn('order_item_id', $order->items()->select('id'))
            ->whereNull('counted_at')
            ->lockForUpdate()
            ->get()
            ->groupBy('inventory_item_id');

        foreach ($rows as $itemId => $lines) {
            $item = InventoryItem::withTrashed()->find($itemId);
            $usage = ItemUsage::where('inventory_item_id', $itemId)
                ->where('status', ItemUsage::STATUS_IN_USE)
                ->lockForUpdate()
                ->first();

            OrderItemIngredient::whereKey($lines->pluck('id'))->update(['item_usage_id' => $usage?->id, 'counted_at' => $at]);

            if (! $usage) {
                if ($item?->tracks_servings) {
                    $notices[] = ['type' => 'warning', 'message' => "No {$item->name} unit is marked as open. Record the stock-out so drinks are counted."];
                }
                continue;
            }

            $usage->servings_used = round($usage->servings_used + $lines->sum('servings'), 2);
            $notices = [...$notices, ...$this->checkStages($usage, $item, $by, $at)];
            $usage->save();
        }

        return $notices;
    }

    /**
     * Items used by the order's not-yet-counted lines that have NO open unit right now
     * (used for the "Open one now?" prompt when marking Ready). Only items being tracked.
     *
     * @return Collection<int, InventoryItem>
     */
    public function itemsWithoutOpenUnit(Order $order): Collection
    {
        $itemIds = OrderItemIngredient::whereIn('order_item_id', $order->items()->select('id'))
            ->whereNull('counted_at')->distinct()->pluck('inventory_item_id');

        return InventoryItem::whereIn('id', $itemIds)
            ->where('tracks_servings', true)
            ->whereDoesntHave('currentUsage')
            ->orderBy('name')->get();
    }

    /** Called when a unit is opened: remember what it is expected to serve (snapshot). */
    public function startUnit(InventoryItem $item, ItemUsage $usage): void
    {
        if (! $item->tracks_servings) {
            return;
        }
        $usual = $item->usualServings();
        $usage->forceFill([
            'expected_servings' => $usual['value'],
            'alert_percent' => $item->alertPercent(),
        ])->save();
    }

    /** Staff checked the container after an alert and it still has enough. Silences the first alert only. */
    public function acknowledge(ItemUsage $usage, ?User $by = null): void
    {
        $by ??= Auth::user();
        $usage->forceFill(['acknowledged_by' => $by->id, 'acknowledged_at' => now()])->save();
        $item = $usage->loadMissing('item')->item;
        AuditLogger::log('inventory', 'servings_acknowledged',
            "Checked the open {$item->name}: still enough after ".ItemUsage::servingsLabel($usage->servings_used).' drink(s)', $usage, null, null, $by);
    }

    /** Manager explains a flagged unit. Usually it is left out of the "usually serves" average. */
    public function resolve(ItemUsage $usage, string $note, bool $excludeFromHistory = true, ?User $by = null): void
    {
        $by ??= Auth::user();
        $usage->forceFill([
            'resolved_by' => $by->id,
            'resolved_at' => now(),
            'resolution_note' => $note,
            'exclude_from_history' => $excludeFromHistory,
        ])->save();
        $item = $usage->loadMissing('item')->item;
        AuditLogger::log('inventory', 'servings_resolved',
            "Resolved over-usage of {$item->name} (".ItemUsage::servingsLabel($usage->servings_used).' drinks): '.$note, $usage,
            null, ['excluded_from_average' => $excludeFromHistory], $by);
    }

    /** @return array<int, array{type:string, message:string}> */
    private function checkStages(ItemUsage $usage, ?InventoryItem $item, ?User $by, Carbon $at): array
    {
        if (! $usage->expected_servings || ! $item) {
            return [];
        }

        $notices = [];
        $pct = $usage->servings_used / $usage->expected_servings * 100;
        $served = ItemUsage::servingsLabel($usage->servings_used);
        $usual = (string) (int) round($usage->expected_servings);   // "about 51", not "about 50.8"

        if ($pct >= ($usage->alert_percent ?: 80) && ! $usage->alerted_at) {
            $usage->alerted_at = $at;
            $notices[] = ['type' => 'warning', 'message' => "{$item->name}: {$served} drinks served from the open {$item->unit}; it usually lasts about {$usual}. Please check it (estimate)."];
            AuditLogger::log('inventory', 'servings_alert', "Estimate alert for open {$item->name}: {$served} of about {$usual} drinks", $usage, null, null, $by);
        }
        if ($pct >= 100 && ! $usage->reached_at) {
            $usage->reached_at = $at;
            $notices[] = ['type' => 'error', 'message' => "{$item->name}: the open {$item->unit} has reached its usual {$usual} drinks. Check it now — mark it depleted or keep using it."];
        }
        if ($pct >= self::FLAG_PERCENT && ! $usage->flagged_at) {
            $usage->flagged_at = $at;
            AuditLogger::log('inventory', 'servings_flagged',
                "Over-usage flagged for {$item->name}: {$served} drinks from one unit (usually about {$usual}) — needs manager review", $usage, null, null, $by);
        }

        return $notices;
    }
}
