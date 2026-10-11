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

    // End-of-shift stock check (Sales ↔ Inventory): item id => [counted, wasted, open_left (yes|no), note]
    public array $check = [];
    public bool $checkReview = false;
    /** Items added by staff that no order used (counted the same way). */
    public array $extraCheckIds = [];
    public $addItemId = '';

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
        $this->check = [];
        $this->checkReview = false;
        $this->extraCheckIds = [];
        $this->addItemId = '';
        $this->showClose = true;
    }

    /* ── End-of-shift stock check ─────────────────────────────
     * Items this shift's sales used are listed; staff count the SEALED units left on the shelf and
     * say whether the unit open at the bar is empty. Blind count: the system's number is shown only
     * on the review step, so staff count what is really there instead of confirming a number.
     */

    /** Count an item no order used (used in the background: spills, staff drinks, supplies). */
    public function addCheckItem(): void
    {
        $this->validate(['addItemId' => ['required', \Illuminate\Validation\Rule::exists('inventory_items', 'id')->whereNull('deleted_at')]],
            ['addItemId.required' => 'Choose an item.'], ['addItemId' => 'item']);
        $this->extraCheckIds = array_values(array_unique([...$this->extraCheckIds, (int) $this->addItemId]));
        $this->addItemId = '';
        $this->checkReview = false;
    }

    public function removeCheckItem(int $id): void
    {
        $this->extraCheckIds = array_values(array_diff($this->extraCheckIds, [$id]));
        unset($this->check[$id]);
    }

    /** Validate the counts and show what will be recorded (nothing is saved yet). */
    public function reviewStockCheck(CashShiftService $service): void
    {
        $shift = CashShift::current();
        if (! $shift) {
            return;
        }
        $items = $service->itemsToCheck($shift, $this->extraCheckIds);

        $rules = [];
        foreach ($items as $item) {
            $rules["check.{$item->id}.counted"] = ['required', 'integer', 'min:0', 'max:100000'];
            $rules["check.{$item->id}.wasted"] = ['nullable', 'integer', 'min:0', 'max:100000'];
            $rules["check.{$item->id}.open_left"] = ['required', 'in:yes,no'];
            $rules["check.{$item->id}.note"] = ['nullable', 'string', 'max:200'];
        }
        $this->validate($rules, [
            'check.*.counted.required' => 'Count the sealed units on the shelf (0 if none).',
            'check.*.counted.integer' => 'Use whole numbers.',
            'check.*.open_left.required' => 'Choose one.',
        ], ['check.*.counted' => 'count', 'check.*.wasted' => 'thrown away']);

        foreach ($items as $item) {
            $missing = max(0, $item->quantity_on_hand - (int) $this->check[$item->id]['counted']);
            if ((int) ($this->check[$item->id]['wasted'] ?? 0) > $missing) {
                $this->addError("check.{$item->id}.wasted", "Only {$missing} {$item->unit} are missing from the shelf.");
            }
        }
        if ($this->getErrorBag()->isEmpty()) {
            $this->checkReview = true;
        }
    }

    public function editStockCheck(): void
    {
        $this->checkReview = false;
    }

    public function saveStockCheck(CashShiftService $service): void
    {
        $shift = CashShift::current();
        if (! $shift || ! $this->checkReview) {
            return;
        }
        $items = $service->itemsToCheck($shift, $this->extraCheckIds)->pluck('id')->all();
        $lines = collect($this->check)->only($items)->map(fn ($l) => [
            'counted' => (int) ($l['counted'] ?? 0),
            'wasted' => (int) ($l['wasted'] ?? 0),
            'open_finished' => ($l['open_left'] ?? '') === 'no',
            'note' => filled($l['note'] ?? null) ? trim($l['note']) : null,
        ])->all();

        $results = $this->attempt(fn () => $service->recordStockCheck($shift, $lines));
        if ($results) {
            $out = $results->sum('stocked_out');
            $wasted = $results->sum('wasted');
            $this->toast('Stock check saved: '.$results->count().' item(s) checked'
                .($out ? ", {$out} unit(s) recorded as stocked out" : '').($wasted ? ", {$wasted} as thrown away" : '')
                .'. Now count the cash.');
            $this->check = [];
            $this->checkReview = false;
            $this->extraCheckIds = [];
            $this->dispatch('stock-changed');
        } else {
            $this->checkReview = false; // something changed (e.g. a new sale); review again
        }
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
        // Under ₱1 is centavo change (e.g. after Senior/PWD sales — the drawer has no centavo coins):
        // still recorded as the variance, but no explanation is required.
        if (abs($variance) >= 1 && mb_strlen(trim($this->closeNotes)) < 5) {
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
        $shift = CashShift::with(['opener', 'cashMovements.user', 'stockChecks'])->where('status', 'open')->first();
        $checkItems = $shift && $this->showClose ? app(CashShiftService::class)->itemsToCheck($shift, $this->extraCheckIds) : collect();
        $addable = $shift && $this->showClose
            ? \App\Models\InventoryItem::active()->whereNotIn('id', $checkItems->pluck('id'))
                ->whereNotIn('id', $shift->stockChecks()->pluck('inventory_item_id'))->orderBy('name')->pluck('name', 'id')
            : collect();

        return view('livewire.sales.cash-drawer', [
            'shift' => $shift,
            'checkItems' => $checkItems,
            'addable' => $addable,
            'summary' => $shift?->summary(),
            'activeOrders' => $shift ? Order::where('cash_shift_id', $shift->id)->activeQueue()->count() : 0,
            'history' => CashShift::with(['opener', 'closer'])->where('status', 'closed')->latest('opened_at')->paginate(10),
            'viewing' => $this->viewingId ? CashShift::with(['opener', 'closer', 'cashMovements.user', 'stockChecks.item', 'stockChecks.checker'])->find($this->viewingId) : null,
        ]);
    }
}
