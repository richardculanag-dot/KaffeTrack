<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\WithToasts;
use App\Models;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Soft-deleted ("archived") records. Nothing is ever hard-deleted from the database;
 * managers can restore anything from here. Restores are written to the audit log.
 */
#[Title('Archive')]
class Archive extends Component
{
    use WithToasts;

    /** key => [label, model class, columns shown] */
    public const SOURCES = [
        'products' => ['Products', Models\Product::class],
        'categories' => ['Menu categories', Models\Category::class],
        'addons' => ['Add-ons', Models\Addon::class],
        'discounts' => ['Discounts', Models\Discount::class],
        'inventory' => ['Inventory items', Models\InventoryItem::class],
        'item_categories' => ['Item categories', Models\ItemCategory::class],
        'suppliers' => ['Suppliers', Models\Supplier::class],
        'expenses' => ['Expenses', Models\Expense::class],
        'expense_categories' => ['Expense categories', Models\ExpenseCategory::class],
        'incomes' => ['Other income', Models\Income::class],
        'users' => ['User accounts', Models\User::class],
    ];

    #[Url]
    public string $source = 'products';

    public function restore(string $source, int $id): void
    {
        abort_unless(isset(self::SOURCES[$source]), 404);
        $class = self::SOURCES[$source][1];
        $record = $class::onlyTrashed()->findOrFail($id);

        // A restored record must not collide with an active one that reuses its unique name.
        $conflict = match ($source) {
            'users' => Models\User::where(fn ($q) => $q->where('email', $record->email)->orWhere('username', $record->username))->exists(),
            'expenses', 'incomes' => false,
            default => $class::whereRaw('lower(name) = ?', [mb_strtolower($record->name)])->exists(),
        };
        if ($conflict) {
            $this->toast('An active record with the same name already exists. Rename or archive it first.', 'error');

            return;
        }

        // Parents must be active before their children come back.
        $parentArchived = match ($source) {
            'products' => $record->category()->withTrashed()->first()?->trashed(),
            'inventory' => $record->category()->withTrashed()->first()?->trashed(),
            'expenses' => $record->category()->withTrashed()->first()?->trashed(),
            default => false,
        };
        if ($parentArchived) {
            $this->toast('Restore its category first — it is also archived.', 'error');

            return;
        }

        $record->restore();
        $this->toast(($record->name ?? $record->description ?? $record->source ?? 'Record').' restored.');
    }

    public function render()
    {
        $counts = collect(self::SOURCES)->map(fn ($s) => $s[1]::onlyTrashed()->count());
        $class = self::SOURCES[$this->source][1] ?? Models\Product::class;

        return view('livewire.admin.archive', [
            'counts' => $counts,
            'records' => $class::onlyTrashed()->latest('deleted_at')->limit(200)->get(),
        ]);
    }
}
