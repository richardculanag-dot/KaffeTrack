<?php

namespace App\Livewire\Inventory;

use App\Livewire\Concerns\WithToasts;
use App\Models\Supplier;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Suppliers')]
class Suppliers extends Component
{
    use WithPagination, WithToasts;

    #[Url(as: 'q')]
    public string $search = '';

    public bool $showForm = false;
    public ?int $editingId = null;
    public array $form = [];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    private function blank(): array
    {
        return ['name' => '', 'contact_person' => '', 'phone' => '', 'email' => '', 'address' => '', 'notes' => '', 'is_active' => true];
    }

    public function create(): void
    {
        $this->resetValidation();
        $this->editingId = null;
        $this->form = $this->blank();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->resetValidation();
        $s = Supplier::findOrFail($id);
        $this->editingId = $id;
        $this->form = array_map(fn ($v) => $v ?? '', $s->only(array_keys($this->blank())));
        $this->form['is_active'] = (bool) $s->is_active;
        $this->showForm = true;
    }

    public function save(): void
    {
        $data = $this->validate([
            'form.name' => ['required', 'string', 'max:150', Rule::unique('suppliers', 'name')->ignore($this->editingId)->whereNull('deleted_at')],
            'form.contact_person' => ['nullable', 'string', 'max:100'],
            'form.phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\-\s]{7,30}$/'],
            'form.email' => ['nullable', 'email', 'max:255'],
            'form.address' => ['nullable', 'string', 'max:255'],
            'form.notes' => ['nullable', 'string', 'max:1000'],
            'form.is_active' => ['boolean'],
        ], ['form.phone.regex' => 'Use digits, spaces, + ( ) or - only.'], [
            'form.name' => 'supplier name', 'form.contact_person' => 'contact person', 'form.phone' => 'phone', 'form.email' => 'email',
        ])['form'];
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);

        if ($this->editingId) {
            Supplier::findOrFail($this->editingId)->update($data);
            $this->toast("{$data['name']} updated.");
        } else {
            Supplier::create($data);
            $this->toast("{$data['name']} added.");
        }
        $this->showForm = false;
    }

    public function archive(int $id): void
    {
        $s = Supplier::findOrFail($id);
        $s->delete();
        $this->toast("{$s->name} archived. Past deliveries keep their supplier name.");
    }

    public function render()
    {
        return view('livewire.inventory.suppliers', [
            'suppliers' => Supplier::query()
                ->withCount('stockIns')
                ->withSum('stockIns', 'total_cost')
                ->withMax('stockIns', 'received_date')
                ->when($this->search, fn ($q) => $q->where(fn ($w) => $w->whereRaw('lower(name) like ?', ['%'.mb_strtolower($this->search).'%'])->orWhereRaw('lower(contact_person) like ?', ['%'.mb_strtolower($this->search).'%'])))
                ->orderBy('name')->paginate(15),
        ]);
    }
}
