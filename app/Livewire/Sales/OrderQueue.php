<?php

namespace App\Livewire\Sales;

use App\Livewire\Concerns\WithToasts;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Services\ServingTracker;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Order queue')]
class OrderQueue extends Component
{
    use WithPagination, WithToasts;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $date = '';

    // Order details slide-over
    public ?int $viewingId = null;

    // "Mark ready" prompt when a drink uses an item with no open unit (Sales ↔ Inventory link)
    public bool $showReadyPrompt = false;
    public ?int $readyPromptId = null;
    /** @var array<int, array{name:string, unit:string, in_storage:int}> */
    public array $readyPromptItems = [];

    // Collect payment
    public bool $showPayment = false;
    public ?int $payingId = null;
    public string $payMethod = 'cash';
    public $payTendered = '';
    public string $payProvider = 'GCash';
    public string $payReference = '';

    // Refund
    public bool $showRefund = false;
    public ?int $refundingId = null;
    public ?int $refundManagerId = null;
    public string $refundPin = '';
    public string $refundReason = '';

    public function mount(): void
    {
        $this->date = $this->date ?: today()->toDateString();
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'status', 'date'])) {
            $this->resetPage();
        }
    }

    /* ── Status transitions ─────────────────────────────── */

    /**
     * $mode: '' = check first · 'open' = open the missing units, then mark ready · 'skip' = mark ready anyway.
     * Never blocked: the prompt only makes recording the stock-out one tap away at the moment it matters.
     */
    public function markReady(OrderService $service, ServingTracker $tracker, InventoryService $inventory, int $id, string $mode = ''): void
    {
        $order = Order::findOrFail($id);

        if ($mode === '' && $order->canMarkReady()) {
            $missing = $tracker->itemsWithoutOpenUnit($order);
            if ($missing->isNotEmpty()) {
                $this->readyPromptId = $order->id;
                $this->readyPromptItems = $missing->map(fn ($i) => ['name' => $i->name, 'unit' => $i->unit, 'in_storage' => $i->quantity_on_hand])->all();
                $this->showReadyPrompt = true;

                return;
            }
        }

        if ($mode === 'open') {
            foreach ($tracker->itemsWithoutOpenUnit($order) as $item) {
                if ($item->quantity_on_hand > 0 && $this->attempt(fn () => $inventory->stockOut($item))) {
                    $this->toast("1 {$item->unit} of {$item->name} is now in use.", 'info');
                }
            }
            $this->dispatch('stock-changed');
        }

        $this->showReadyPrompt = false;
        if ($this->attempt(fn () => $service->markReady($order), "Order #{$order->queue_number} is ready — call the customer.")) {
            foreach ($service->notices as $notice) {
                $this->toast($notice['message'], $notice['type']);
            }
            $this->dispatch('stock-changed'); // keep the bell's drink counts current
        }
    }

    public function complete(OrderService $service, int $id): void
    {
        $order = Order::findOrFail($id);
        if (! $order->isPaid()) {
            $this->openPayment($id);

            return;
        }
        $this->attempt(fn () => $service->complete($order), "Order #{$order->queue_number} completed.");
    }

    public function cancel(OrderService $service, int $id, string $reason = ''): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            $this->toast('Enter a reason for cancelling.', 'error');

            return;
        }
        $order = Order::findOrFail($id);
        $this->attempt(fn () => $service->cancel($order, mb_substr($reason, 0, 200)), "Order #{$order->queue_number} cancelled.");
    }

    /* ── Collect payment for an unpaid order ─────────────── */

    public function openPayment(int $id): void
    {
        $this->resetValidation();
        $this->payingId = $id;
        $this->payMethod = 'cash';
        $this->payTendered = '';
        $this->payProvider = 'GCash';
        $this->payReference = '';
        $this->showPayment = true;
    }

    public function updatedPayMethod(): void
    {
        $this->payProvider = Payment::PROVIDERS[$this->payMethod][0] ?? '';
        $this->resetValidation();
    }

    public function collectPayment(OrderService $service): void
    {
        $order = Order::findOrFail($this->payingId);
        $this->validate([
            'payMethod' => ['required', Rule::in(array_keys(Payment::METHODS))],
            'payTendered' => [Rule::requiredIf($this->payMethod === 'cash'), 'nullable', 'numeric', 'min:'.$order->total_amount],
            'payReference' => [Rule::requiredIf($this->payMethod !== 'cash'), 'nullable', 'string', 'min:4', 'max:100'],
        ], [
            'payTendered.min' => 'Cash received must be at least '.peso($order->total_amount).'.',
            'payTendered.required' => 'Enter the cash received.',
            'payReference.required' => 'Enter the transaction reference number.',
        ]);

        $payment = $this->attempt(fn () => $service->recordPayment($order, [
            'method' => $this->payMethod,
            'amount_tendered' => $this->payMethod === 'cash' ? (float) $this->payTendered : null,
            'provider' => $this->payMethod === 'cash' ? null : $this->payProvider,
            'reference_number' => $this->payMethod === 'cash' ? null : trim($this->payReference),
        ]));

        if ($payment) {
            $this->showPayment = false;
            $change = (float) $payment->change_amount;
            $this->toast("Payment recorded for #{$order->queue_number}.".($change > 0 ? ' Give change of '.peso($change).'.' : ''));
            $this->dispatch('shift-changed');
        }
    }

    /* ── Refund (manager PIN) ─────────────────────────────── */

    public function openRefund(int $id): void
    {
        $this->resetValidation();
        $this->refundingId = $id;
        $this->refundManagerId = auth()->user()->isManager() ? auth()->id() : User::active()->where('role', 'manager')->value('id');
        $this->refundPin = '';
        $this->refundReason = '';
        $this->showRefund = true;
    }

    public function refund(OrderService $service): void
    {
        $this->validate([
            'refundManagerId' => ['required', 'integer'],
            'refundPin' => ['required', 'digits_between:4,6'],
            'refundReason' => ['required', 'string', 'min:5', 'max:200'],
        ], [], ['refundManagerId' => 'manager', 'refundPin' => 'PIN', 'refundReason' => 'reason']);

        $manager = User::active()->where('role', 'manager')->find($this->refundManagerId);

        // Throttle PIN guessing: 5 wrong PINs per 5 minutes per session.
        $key = 'refund-pin:'.session()->getId();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('refundPin', 'Too many wrong PINs. Try again in a few minutes.');

            return;
        }
        if (! $manager || ! $manager->checkPin($this->refundPin)) {
            RateLimiter::hit($key, 300);
            $this->addError('refundPin', 'That PIN is not correct for the selected manager.');
            AuditLogger::log('sales', 'refund_denied', 'Refund approval failed — wrong manager PIN', Order::find($this->refundingId));

            return;
        }
        RateLimiter::clear($key);

        $order = Order::with('payment')->findOrFail($this->refundingId);
        $refund = $this->attempt(fn () => $service->refund($order, trim($this->refundReason), $manager));
        if ($refund) {
            $this->showRefund = false;
            $how = $refund->method === 'cash' ? 'Return '.peso($refund->amount).' in cash to the customer.' : 'Reverse the '.ucfirst($refund->method).' payment of '.peso($refund->amount).' on the terminal/app.';
            $this->toast("Order #{$order->queue_number} refunded. {$how}");
            $this->dispatch('shift-changed');
        }
    }

    public function view(int $id): void
    {
        $this->viewingId = $id;
    }

    public function render()
    {
        $queue = Order::with(['items.addons', 'payment'])
            ->activeQueue()->orderBy('created_at')->get()
            ->groupBy('status');

        $history = Order::with(['cashier', 'payment'])
            ->when($this->date, fn ($q) => $q->whereDate('created_at', $this->date))
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->when($this->search, function ($q) {
                $s = '%'.mb_strtolower(trim($this->search)).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(order_number) like ?', [$s])
                    ->orWhereRaw('lower(customer_name) like ?', [$s])
                    ->orWhereRaw('CAST(queue_number AS TEXT) like ?', [trim($this->search, '#').'%']));
            })
            ->latest()->paginate(12);

        return view('livewire.sales.order-queue', [
            'pending' => $queue->get('pending', collect()),
            'ready' => $queue->get('ready', collect()),
            'history' => $history,
            'viewing' => $this->viewingId ? Order::with(['items.addons', 'payment.receiver', 'cashier', 'refund.approver', 'refund.processor'])->find($this->viewingId) : null,
            'paying' => $this->payingId ? Order::find($this->payingId) : null,
            'refunding' => $this->refundingId ? Order::with('payment')->find($this->refundingId) : null,
            'managers' => User::active()->where('role', 'manager')->orderBy('name')->pluck('name', 'id'),
        ]);
    }
}
