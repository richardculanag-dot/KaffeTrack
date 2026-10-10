<?php

namespace App\Livewire\Sales;

use App\Exceptions\BusinessRuleException;
use App\Livewire\Concerns\WithToasts;
use App\Models\CashShift;
use App\Models\Category;
use App\Models\Discount;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\CashShiftService;
use App\Services\OrderService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Point of Sale.
 *
 * The cart lives in the browser (Alpine) so tapping products is instant even on a slow
 * connection to the cloud database. Only checkout reaches the server, where every price is
 * re-read from the database and totals are recomputed — the browser is never trusted.
 */
#[Title('Point of sale')]
class Pos extends Component
{
    use WithToasts;

    public function openShift(CashShiftService $service, $openingCash): void
    {
        if (! is_numeric($openingCash) || $openingCash < 0 || $openingCash > 1000000) {
            $this->toast('Enter the starting cash in the drawer (0 or more).', 'error');

            return;
        }

        if ($this->attempt(fn () => $service->open((float) $openingCash), 'Cash drawer opened. You can start taking orders.')) {
            $this->dispatch('shift-changed');
        }
    }

    /**
     * Called from the browser with the cart. Returns a plain array the UI uses for the success screen.
     */
    public function checkout(OrderService $orders, array $cart, array $meta, ?array $payment = null): array
    {
        try {
            $data = Validator::make(compact('cart', 'meta', 'payment'), [
                'cart' => ['required', 'array', 'min:1', 'max:50'],
                'cart.*.variant_id' => ['required', 'integer'],
                'cart.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
                'cart.*.addon_ids' => ['array'],
                'cart.*.addon_ids.*' => ['integer'],
                'cart.*.notes' => ['nullable', 'string', 'max:120'],
                'meta.order_type' => ['required', Rule::in(array_keys(Order::TYPES))],
                'meta.customer_name' => ['nullable', 'string', 'max:100'],
                // Only checked for dine-in; takeout orders never store a table.
                'meta.table_number' => ['exclude_unless:meta.order_type,dine_in', 'nullable', 'integer', 'between:1,'.Order::tableCount()],
                'meta.notes' => ['nullable', 'string', 'max:255'],
                'meta.discount_id' => ['nullable', 'integer'],
                'meta.discount_id_number' => ['nullable', 'string', 'max:50'],
                'payment' => ['nullable', 'array'],
                'payment.method' => ['required_with:payment', Rule::in(array_keys(Payment::METHODS))],
                'payment.amount_tendered' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
                'payment.provider' => ['nullable', 'string', 'max:50'],
                'payment.reference_number' => ['nullable', 'string', 'max:100'],
            ], [
                'cart.required' => 'The cart is empty. Add at least one item.',
                'meta.order_type.required' => 'Choose dine-in or takeout.',
                'meta.table_number.integer' => 'Choose a table from the list.',
                'meta.table_number.between' => 'Choose a table from 1 to '.Order::tableCount().'.',
            ])->validate();

            $order = $orders->place($data['cart'], $data['meta'], $data['payment'] ?? null);
        } catch (ValidationException $e) {
            return ['ok' => false, 'message' => collect($e->errors())->flatten()->first()];
        } catch (BusinessRuleException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $this->dispatch('shift-changed');

        return [
            'ok' => true,
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'queue_number' => $order->queue_number,
            'total' => (float) $order->total_amount,
            'paid' => $order->isPaid(),
            'method' => $order->payment?->methodLabel(),
            'change' => (float) ($order->payment?->change_amount ?? 0),
            'receipt_url' => route('orders.receipt', $order),
        ];
    }

    public function render()
    {
        $categories = Category::where('is_active', true)->orderBy('sort_order')->orderBy('name')
            ->with(['products' => fn ($q) => $q->available()->orderBy('name')->with(['variants', 'addons' => fn ($a) => $a->where('is_active', true), 'ingredients.item.currentUsage'])])
            ->get()
            ->filter(fn ($c) => $c->products->isNotEmpty())
            ->values();

        $catalog = $categories->map(fn ($c) => [
            'id' => $c->id,
            'name' => $c->name,
            'icon' => $c->icon ?: 'coffee',
            'products' => $c->products->filter(fn ($p) => $p->variants->isNotEmpty())->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description,
                'image' => $p->imageUrl(),
                'variants' => $p->variants->map(fn ($v) => ['id' => $v->id, 'name' => $v->name, 'price' => (float) $v->price])->values(),
                'addons' => $p->addons->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'price' => (float) $a->price])->values(),
                // Soft warning only (never blocks): a linked item shows nothing in storage and nothing open.
                // An add-on may substitute it (e.g. oat milk), and the records can be behind reality.
                'stock_note' => ($out = $p->ingredients->filter(fn ($i) => $i->item && $i->item->quantity_on_hand <= 0 && ! $i->item->currentUsage)
                    ->map(fn ($i) => $i->item->name)->unique()->values())->isNotEmpty()
                    ? $out->join(', ').' shows none left — check before confirming'
                    : null,
            ])->values(),
        ]);

        $shift = CashShift::current();

        return view('livewire.sales.pos', [
            'catalog' => $catalog,
            'discounts' => Discount::where('is_active', true)->orderBy('name')->get()
                ->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'label' => $d->label(), 'type' => $d->type, 'value' => (float) $d->value, 'vat_exempt' => $d->is_vat_exempt, 'requires_id' => $d->requires_id]),
            'shift' => $shift,
            'nextQueue' => Order::withTrashed()->whereDate('created_at', today())->count() + 1,
            'vat' => ['registered' => Setting::bool('vat_registered'), 'rate' => (float) Setting::get('vat_rate', 12)],
            'providers' => Payment::PROVIDERS,
        ]);
    }
}
