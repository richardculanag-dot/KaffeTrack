<?php

namespace App\Livewire\Shell;

use App\Models\CashShift;
use App\Models\InventoryItem;
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
