<?php

namespace App\Livewire\Inventory;

use App\Models\InventoryItem;
use App\Models\ItemUsage;
use App\Models\StockMovement;
use App\Models\User;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Stock movements')]
class Movements extends Component
{
    use WithPagination;

    #[Url]
    public string $tab = 'ledger'; // ledger | usage

    #[Url]
    public $item = '';

    #[Url]
    public string $type = '';

    #[Url]
    public string $user = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function mount(): void
    {
        $this->from = $this->from ?: today()->subDays(13)->toDateString();
        $this->to = $this->to ?: today()->toDateString();
    }

    public function updating($name): void
    {
        // Two paginated tables on one page, each with its own page parameter.
        $this->resetPage('ledgerPage');
        $this->resetPage('usagePage');
    }

    public function clearFilters(): void
    {
        $this->reset('item', 'type', 'user');
        $this->from = today()->subDays(13)->toDateString();
        $this->to = today()->toDateString();
    }

    public function render()
    {
        $range = [$this->from.' 00:00:00', $this->to.' 23:59:59'];

        $movements = StockMovement::with(['item', 'user', 'batch'])
            ->whereBetween('created_at', $range)
            ->when($this->item, fn ($q) => $q->where('inventory_item_id', $this->item))
            ->when($this->type, fn ($q) => $q->where('type', $this->type))
            ->when($this->user, fn ($q) => $q->where('user_id', $this->user))
            ->latest('created_at')->latest('id')
            ->paginate(20, pageName: 'ledgerPage');

        $usages = ItemUsage::with(['item', 'opener', 'closer'])
            ->whereBetween('opened_at', $range)
            ->when($this->item, fn ($q) => $q->where('inventory_item_id', $this->item))
            ->when($this->user, fn ($q) => $q->where(fn ($w) => $w->where('opened_by', $this->user)->orWhere('closed_by', $this->user)))
            ->latest('opened_at')
            ->paginate(20, pageName: 'usagePage');

        $totals = StockMovement::whereBetween('created_at', $range)
            ->when($this->item, fn ($q) => $q->where('inventory_item_id', $this->item))
            ->when($this->user, fn ($q) => $q->where('user_id', $this->user))
            ->selectRaw('type, SUM(ABS(quantity)) as qty')->groupBy('type')->pluck('qty', 'type');

        return view('livewire.inventory.movements', [
            'movements' => $movements,
            'usages' => $usages,
            'totals' => $totals,
            'items' => InventoryItem::withTrashed()->orderBy('name')->pluck('name', 'id'),
            'users' => User::withTrashed()->orderBy('name')->pluck('name', 'id'),
        ]);
    }
}
