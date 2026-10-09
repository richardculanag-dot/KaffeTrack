<?php

namespace App\Livewire\Products;

use App\Livewire\Concerns\WithToasts;
use App\Models\Discount;
use App\Models\Order;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Discounts')]
class Discounts extends Component
{
    use WithToasts;

    public bool $showForm = false;
    public ?int $editingId = null;
    public array $form = [];

    public function create(): void
    {
        $this->resetValidation();
        $this->editingId = null;
        $this->form = ['name' => '', 'type' => 'percentage', 'value' => '', 'is_vat_exempt' => false, 'requires_id' => false, 'is_active' => true];
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->resetValidation();
        $d = Discount::findOrFail($id);
        $this->editingId = $id;
        $this->form = ['name' => $d->name, 'type' => $d->type, 'value' => (float) $d->value, 'is_vat_exempt' => $d->is_vat_exempt, 'requires_id' => $d->requires_id, 'is_active' => $d->is_active];
        $this->showForm = true;
    }

    public function save(): void
    {
        $data = $this->validate([
            'form.name' => ['required', 'string', 'max:100', Rule::unique('discounts', 'name')->ignore($this->editingId)->whereNull('deleted_at')],
            'form.type' => ['required', Rule::in(['percentage', 'fixed'])],
            'form.value' => ['required', 'numeric', 'min:0.01', $this->form['type'] === 'percentage' ? 'max:100' : 'max:100000'],
            'form.is_vat_exempt' => ['boolean'],
            'form.requires_id' => ['boolean'],
            'form.is_active' => ['boolean'],
        ], ['form.value.max' => $this->form['type'] === 'percentage' ? 'A percentage cannot be more than 100.' : 'That amount is too large.'],
            ['form.name' => 'discount name', 'form.value' => 'value'])['form'];

        $this->editingId ? Discount::findOrFail($this->editingId)->update($data) : Discount::create($data);
        $this->toast($this->editingId ? 'Discount updated.' : "Discount “{$data['name']}” created.");
        $this->showForm = false;
    }

    public function archive(int $id): void
    {
        $d = Discount::findOrFail($id);
        $d->delete();
        $this->toast("Discount “{$d->name}” archived.");
    }

    public function render()
    {
        $usage = Order::where('payment_status', 'paid')->whereNotNull('discount_id')->where('created_at', '>=', today()->subDays(29))
            ->selectRaw('discount_id, COUNT(*) as n, SUM(discount_amount) as total')->groupBy('discount_id')->get()->keyBy('discount_id');

        return view('livewire.products.discounts', [
            'discounts' => Discount::orderBy('name')->get(),
            'usage' => $usage,
        ]);
    }
}
