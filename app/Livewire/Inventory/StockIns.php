<?php

namespace App\Livewire\Inventory;

use App\Models\StockIn;
use App\Models\Supplier;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Stock in')]
class StockIns extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $supplier = '';

    #[Url]
    public string $month = '';

    public $viewingId = null;

    public function updating($name): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $query = StockIn::with(['supplier', 'receiver'])->withCount('batches')
            ->when($this->search, fn ($q) => $q->where(fn ($w) => $w->whereRaw('lower(reference_no) like ?', ['%'.mb_strtolower($this->search).'%'])->orWhereRaw('lower(invoice_number) like ?', ['%'.mb_strtolower($this->search).'%'])))
            ->when($this->supplier, fn ($q) => $q->where('supplier_id', $this->supplier))
            ->when($this->month, fn ($q) => $q->whereRaw("to_char(received_date, 'YYYY-MM') = ?", [$this->month]));

        return view('livewire.inventory.stock-ins', [
            'stockIns' => (clone $query)->latest('received_date')->latest('id')->paginate(15),
            'total' => (float) (clone $query)->sum('total_cost'),
            'suppliers' => Supplier::withTrashed()->orderBy('name')->pluck('name', 'id'),
            'viewing' => $this->viewingId ? StockIn::with(['supplier', 'receiver', 'batches.item', 'expense'])->find($this->viewingId) : null,
        ]);
    }
}
