<?php

namespace App\Livewire\Reports;

use App\Models\AuditLog;
use App\Services\Analytics;
use App\Services\ReportBuilder;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Reports')]
class Index extends Component
{
    public const PRESETS = [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        '7d' => 'Last 7 days',
        'month' => 'This month',
        'last_month' => 'Last month',
        'custom' => 'Custom',
    ];

    #[Url]
    public string $type = 'sales';

    #[Url]
    public string $preset = 'month';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $module = '';

    public bool $showPreview = false;

    public function mount(): void
    {
        if (! array_key_exists($this->type, ReportBuilder::TYPES)) {
            $this->type = 'sales';
        }
        if ($this->from && $this->to) {
            $this->preset = 'custom';
        } else {
            $this->applyPreset();
        }
    }

    public function updatedPreset(): void
    {
        $this->applyPreset();
    }

    public function updatedFrom(): void
    {
        $this->preset = 'custom';
    }

    public function updatedTo(): void
    {
        $this->preset = 'custom';
    }

    private function applyPreset(): void
    {
        [$f, $t] = match ($this->preset) {
            'today' => [today(), today()],
            'yesterday' => [today()->subDay(), today()->subDay()],
            '7d' => [today()->subDays(6), today()],
            'last_month' => [today()->subMonthNoOverflow()->startOfMonth(), today()->subMonthNoOverflow()->endOfMonth()],
            'custom' => [Carbon::parse($this->from ?: today()), Carbon::parse($this->to ?: today())],
            default => [today()->startOfMonth(), today()],
        };
        $this->from = $f->toDateString();
        $this->to = $t->toDateString();
    }

    public function render()
    {
        $errors = [];
        try {
            $from = Carbon::parse($this->from);
            $to = Carbon::parse($this->to);
        } catch (\Throwable) {
            $from = $to = today();
            $errors[] = 'Enter valid dates.';
        }
        if ($from->gt($to)) {
            $errors[] = 'The start date must be on or before the end date.';
        }
        if ($to->gt(today())) {
            $errors[] = 'The end date cannot be in the future.';
        }
        if ($from->diffInDays($to) > 366) {
            $errors[] = 'A report can cover at most one year.';
        }

        $params = array_filter(['type' => $this->type, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'module' => $this->type === 'audit' ? $this->module : null]);

        return view('livewire.reports.index', [
            'types' => ReportBuilder::TYPES,
            'summary' => empty($errors) ? (new Analytics($from, $to))->summary() : null,
            'errorsList' => $errors,
            'downloadUrl' => route('reports.export', $params),
            'previewUrl' => route('reports.export', $params + ['inline' => 1]),
            'modules' => AuditLog::MODULES,
            'rangeLabel' => $from->isSameDay($to) ? $from->format('M j, Y') : $from->format('M j, Y').' – '.$to->format('M j, Y'),
        ]);
    }
}
