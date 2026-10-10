<?php

namespace App\Livewire\Shell;

use App\Models\CashShift;
use App\Models\InventoryItem;
use App\Models\ItemUsage;
use App\Models\OrderItemIngredient;
use App\Models\StockBatch;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Top-bar status: is the cash drawer open, and are there stock alerts?
 * Keeps system status visible on every screen (Nielsen heuristic #1).
 * Alerts are shown to everyone — staff are the ones opening stock at the bar.
 */
class StatusBar extends Component
{
    #[On('shift-changed')]
    #[On('stock-changed')]
    public function refreshStatus(): void
    {
        // Listening is enough: Livewire re-renders the component.
    }

    public function render()
    {
        $shift = CashShift::with('opener')->where('status', 'open')->first();
        $alerts = collect();
        $isManager = (bool) auth()->user()?->isManager();

        // Sales ↔ Inventory estimates: open units nearing / past what a unit usually serves.
        $estimates = ItemUsage::with('item:id,name,unit')
            ->where('status', ItemUsage::STATUS_IN_USE)
            ->where(fn ($q) => $q->whereNotNull('reached_at')->orWhere(fn ($w) => $w->whereNotNull('alerted_at')->whereNull('acknowledged_at')))
            ->orderByDesc('servings_used')->limit(6)->get();
        foreach ($estimates as $u) {
            $alerts->push([
                'tone' => $u->reached_at ? 'red' : 'amber',
                'title' => $u->item?->name,
                'tag' => 'Estimate',
                'text' => ItemUsage::servingsLabel($u->servings_used).' drinks served from the open '.$u->item?->unit.' (usually ~'.(int) round($u->expected_servings).'). Check the container'
                    .($shift ? ' — '.$shift->opener->name.' has the shift.' : '.'),
            ]);
        }

        // Drinks made today while no unit of a tracked item was recorded as open.
        $unassigned = OrderItemIngredient::query()
            ->join('inventory_items', 'inventory_items.id', '=', 'order_item_ingredients.inventory_item_id')
            ->where('inventory_items.tracks_servings', true)
            ->whereNull('order_item_ingredients.item_usage_id')
            ->whereNotNull('order_item_ingredients.counted_at')
            ->where('order_item_ingredients.counted_at', '>=', today())
            // …and still no unit opened since then
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('item_usages')
                ->whereColumn('item_usages.inventory_item_id', 'order_item_ingredients.inventory_item_id')
                ->whereColumn('item_usages.opened_at', '>=', 'order_item_ingredients.counted_at'))
            ->groupBy('inventory_items.name')
            ->selectRaw('inventory_items.name, SUM(order_item_ingredients.servings) as drinks')
            ->get();
        foreach ($unassigned as $row) {
            $alerts->push([
                'tone' => 'amber',
                'title' => $row->name,
                'tag' => 'Not recorded',
                'text' => ItemUsage::servingsLabel((float) $row->drinks).' drink(s) made today with no open unit recorded. Record the stock-out in Stock on hand.',
            ]);
        }

        // Exceptions for the manager: a unit served far more than usual (something wasn't recorded).
        if ($isManager) {
            $flagged = ItemUsage::with('item:id,name,unit')->whereNotNull('flagged_at')->whereNull('resolved_at')->latest('flagged_at')->limit(6)->get();
            foreach ($flagged as $u) {
                $alerts->push([
                    'tone' => 'red',
                    'title' => $u->item?->name,
                    'tag' => 'Review',
                    'text' => ItemUsage::servingsLabel($u->servings_used).' drinks counted on one '.$u->item?->unit.' (usually ~'.(int) round($u->expected_servings).'). Check storage, then review it in Stock on hand.',
                ]);
            }
        }

        $low = InventoryItem::active()->lowStock()->orderBy('quantity_on_hand')->limit(6)
            ->get(['id', 'name', 'quantity_on_hand', 'reorder_level', 'unit']);
        foreach ($low as $item) {
            $alerts->push([
                'tone' => $item->quantity_on_hand <= 0 ? 'red' : 'amber',
                'title' => $item->name,
                'text' => $item->quantity_on_hand <= 0
                    ? 'Out of stock'
                    : "Low stock — {$item->quantity_on_hand} left (reorder at {$item->reorder_level})",
            ]);
        }

        $expiring = StockBatch::with('item:id,name')
            ->where('quantity_remaining', '>', 0)
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<=', today()->addDays(3))
            ->orderBy('expiry_date')->limit(6)->get();
        foreach ($expiring as $batch) {
            $expired = $batch->expiry_date->lt(today());
            $alerts->push([
                'tone' => $expired ? 'red' : 'amber',
                'title' => $batch->item?->name,
                'text' => ($expired ? 'Expired on ' : 'Expires on ').$batch->expiry_date->format('M j')
                    ." — {$batch->quantity_remaining} unit(s) in storage",
            ]);
        }

        return view('livewire.shell.status-bar', compact('shift', 'alerts'));
    }
}
