<?php

namespace App\Livewire\Sales;

use App\Livewire\Concerns\WithToasts;
use App\Models\CashShift;
use App\Models\Order;
use App\Services\CashShiftService;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Cash drawer')]
class CashDrawer extends Component
{
    use WithPagination, WithToasts;

    public $openingCash = '3000';
    public string $openingNotes = '';

    public bool $showMovement = false;
    public string $movementType = 'cash_out';
    public $movementAmount = '';
    public string $movementReason = '';

    public bool $showClose = false;
    public array $counts = [];
    public string $closeNotes = '';

    public $viewingId = null; // untyped: the modal sets it to false when closed

    public function mount(): void
    {
        $this->resetCounts();
    }

    private function resetCounts(): void
    {
        $this->counts = collect(CashShiftService::DENOMINATIONS)->mapWithKeys(fn ($d) => [(string) $d => ''])->all();
    }

    public function open(CashShiftService $service): void
    {
        $this->validate([
            'openingCash' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'openingNotes' => ['nullable', 'string', 'max:255'],
        ], [], ['openingCash' => 'starting cash']);

        if ($this->attempt(fn () => $service->open((float) $this->openingCash, $this->openingNotes ?: null), 'Cash drawer opened.')) {
            $this->reset('openingNotes');
            $this->dispatch('shift-changed');
        }
    }

    public function startMovement(string $type): void
    {
        $this->resetValidation();
        $this->movementType = $type === 'cash_in' ? 'cash_in' : 'cash_out';
        $this->movementAmount = '';
        $this->movementReason = '';
        $this->showMovement = true;
    }

    public function saveMovement(CashShiftService $service): void
    {
        $this->validate([
            'movementAmount' => ['required', 'numeric', 'min:1', 'max:1000000'],
            'movementReason' => ['required', 'string', 'min:3', 'max:255'],
        ], [], ['movementAmount' => 'amount', 'movementReason' => 'reason']);

        $shift = CashShift::current();
        if (! $shift) {
            $this->toast('No shift is open.', 'error');

            return;
        }

        $ok = $this->attempt(fn () => $service->addMovement($shift, $this->movementType, (float) $this->movementAmount, trim($this->movementReason)),
            ($this->movementType === 'cash_in' ? 'Cash in' : 'Cash out').' of '.peso($this->movementAmount).' recorded.');
        if ($ok) {
            $this->showMovement = false;
        }
    }

    public function startClose(): void
    {
        $this->resetValidation();
        $this->resetCounts();
        $this->closeNotes = '';
        $this->showClose = true;
    }

    public function getCountedProperty(): float
    {
        return collect($this->counts)->reduce(fn ($sum, $n, $d) => $sum + ((int) $d * max(0, (int) $n)), 0);
    }

    public function close(CashShiftService $service): void
    {
        $shift = CashShift::current();
        if (! $shift) {
            $this->toast('No shift is open.', 'error');

            return;
        }

        $this->validate([
            'counts.*' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'closeNotes' => ['nullable', 'string', 'max:500'],
        ], ['counts.*.integer' => 'Use whole numbers.', 'counts.*.min' => 'Cannot be negative.']);

        $variance = round($this->counted - $shift->computeExpectedCash(), 2);
        // Accountability: any over/short must be explained before the drawer can be closed.
        if (abs($variance) >= 0.01 && mb_strlen(trim($this->closeNotes)) < 5) {
            $this->addError('closeNotes', 'The count is '.($variance > 0 ? 'over' : 'short').' by '.peso(abs($variance)).'. Add a short explanation.');

            return;
        }

        $closed = $this->attempt(fn () => $service->close($shift, $this->counts, trim($this->closeNotes) ?: null));
        if ($closed) {
            $this->showClose = false;
            $this->toast('Shift closed. '.($closed->variance == 0 ? 'The drawer balanced exactly.' : 'Variance: '.peso($closed->variance).'.'));
            $this->dispatch('shift-changed');
        }
    }

    public function render()
    {
        $shift = CashShift::with(['opener', 'cashMovements.user'])->where('status', 'open')->first();

        return view('livewire.sales.cash-drawer', [
            'shift' => $shift,
            'summary' => $shift?->summary(),
            'activeOrders' => $shift ? Order::where('cash_shift_id', $shift->id)->activeQueue()->count() : 0,
            'history' => CashShift::with(['opener', 'closer'])->where('status', 'closed')->latest('opened_at')->paginate(10),
            'viewing' => $this->viewingId ? CashShift::with(['opener', 'closer', 'cashMovements.user'])->find($this->viewingId) : null,
        ]);
    }
}
