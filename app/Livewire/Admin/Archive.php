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
        // Compared the same way the forms do: ignoring case and extra spaces.
        $same = fn (string $column, ?string $value) => $value !== null && $value !== ''
            && $class::whereRaw("lower(regexp_replace(trim({$column}), '\\s+', ' ', 'g')) = ?", [mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)))])->exists();

        $conflict = match ($source) {
            'users' => $same('username', $record->username) ? 'username' : ($same('email', $record->email) ? 'email' : null),
            'expenses', 'incomes' => null,
            'products' => $same('name', $record->name) ? 'name' : ($same('code', $record->code) ? 'product code' : null),
            'inventory' => $same('name', $record->name) ? 'name' : ($same('sku', $record->sku) ? 'SKU' : null),
            default => $same('name', $record->name) ? 'name' : null,
        };
        if ($conflict) {
            $this->toast("An active record already uses the same {$conflict}. Rename or archive it first.", 'error');

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
        if (! isset(self::SOURCES[$this->source])) {
            $this->source = 'products'; // ?source=anything-else in the URL
        }
        $counts = collect(self::SOURCES)->map(fn ($s) => $s[1]::onlyTrashed()->count());
        $class = self::SOURCES[$this->source][1];

        return view('livewire.admin.archive', [
            'counts' => $counts,
            'records' => $class::onlyTrashed()->latest('deleted_at')->limit(200)->get(),
        ]);
    }
}
