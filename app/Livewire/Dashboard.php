<?php

namespace App\Livewire;

use App\Models\CashShift;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Services\Analytics;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Dashboard')]
class Dashboard extends Component
{
    public const PERIODS = ['today' => 'Today', '7d' => 'Last 7 days', '30d' => 'Last 30 days', 'month' => 'This month'];

    #[Url]
    public string $period = '30d';

    public function updatedPeriod(): void
    {
        if (! array_key_exists($this->period, self::PERIODS)) {
            $this->period = '30d';
        }
    }

    public function render()
    {
        $a = Analytics::period($this->period);
        $prev = $a->previous();
        $now = $a->summary();
        $before = $prev->summary();

        $series = $a->salesSeries();
        $peak = $a->peakHours();
        $top = $a->topProducts(7);
        $categories = $a->salesByCategory();
        $mix = $a->paymentMix();

        $charts = [
            'sales' => [
                'type' => 'bar',
                'money' => 'y',
                'data' => [
                    'labels' => $series['labels'],
                    'datasets' => [
                        ['type' => 'bar', 'label' => 'Net sales', 'data' => $series['sales'], 'backgroundColor' => '#3f845c', 'hoverBackgroundColor' => '#2f6b4f', 'borderRadius' => 5, 'maxBarThickness' => 26, 'order' => 2],
                        ['type' => 'line', 'label' => 'Orders', 'data' => $series['orders'], 'yAxisID' => 'y2', 'money' => false, 'borderColor' => '#b57a3e', 'backgroundColor' => '#b57a3e', 'tension' => .35, 'pointRadius' => 0, 'pointHoverRadius' => 4, 'borderWidth' => 2, 'order' => 1],
                    ],
                ],
                'options' => [
                    'interaction' => ['mode' => 'index', 'intersect' => false],
                    'plugins' => ['legend' => ['position' => 'top', 'align' => 'end']],
                    'scales' => [
                        'x' => ['grid' => ['display' => false], 'ticks' => ['maxTicksLimit' => 10, 'maxRotation' => 0]],
                        'y' => ['beginAtZero' => true, 'grid' => ['color' => '#eef3ef']],
                        'y2' => ['beginAtZero' => true, 'position' => 'right', 'grid' => ['display' => false], 'ticks' => ['precision' => 0]],
                    ],
                ],
            ],
            'peak' => [
                'type' => 'bar',
                'data' => ['labels' => $peak['labels'], 'datasets' => [[
                    'label' => 'Avg. orders', 'data' => $peak['values'], 'borderRadius' => 4,
                    'backgroundColor' => collect($peak['values'])->map(fn ($v) => $v >= max(1, max($peak['values']) * .75) ? '#b57a3e' : '#c3dcc9')->all(),
                ]]],
                'options' => ['plugins' => ['legend' => ['display' => false]], 'scales' => ['x' => ['grid' => ['display' => false], 'ticks' => ['maxRotation' => 0, 'autoSkip' => true, 'maxTicksLimit' => 9]], 'y' => ['beginAtZero' => true, 'grid' => ['color' => '#eef3ef']]]],
            ],
            'top' => [
                'type' => 'bar',
                'data' => ['labels' => $top->pluck('name'), 'datasets' => [[
                    'label' => 'Cups / pieces sold', 'data' => $top->pluck('qty'), 'backgroundColor' => '#2f6b4f', 'borderRadius' => 4, 'maxBarThickness' => 18,
                ]]],
                'options' => ['indexAxis' => 'y', 'plugins' => ['legend' => ['display' => false]], 'scales' => ['x' => ['beginAtZero' => true, 'grid' => ['color' => '#eef3ef']], 'y' => ['grid' => ['display' => false]]]],
            ],
            'mix' => [
                'type' => 'doughnut',
                'peso' => true,
                'data' => ['labels' => $mix->pluck('method'), 'datasets' => [[
                    'data' => $mix->pluck('total'), 'backgroundColor' => ['#2f6b4f', '#b57a3e', '#2e6a8e'], 'borderWidth' => 2, 'borderColor' => '#fff', 'hoverOffset' => 6,
                ]]],
                'options' => ['cutout' => '68%', 'plugins' => ['legend' => ['position' => 'bottom']]],
            ],
        ];

        $live = [
            'pending' => Order::where('status', 'pending')->count(),
            'ready' => Order::where('status', 'ready')->count(),
            'shift' => CashShift::with('opener')->where('status', 'open')->first(),
            'low' => InventoryItem::active()->lowStock()->orderBy('quantity_on_hand')->limit(5)->get(),
        ];
        $recent = Order::with('cashier', 'payment')->latest()->limit(6)->get();

        return view('livewire.dashboard', [
            'now' => $now,
            'before' => $before,
            'charts' => $charts,
            'categories' => $categories,
            'mix' => $mix,
            'live' => $live,
            'recent' => $recent,
            'rangeLabel' => $a->from->isSameDay($a->to) ? $a->from->format('M j, Y') : $a->from->format('M j').' – '.$a->to->format('M j, Y'),
            'prevLabel' => $prev->from->isSameDay($prev->to) ? $prev->from->format('M j') : $prev->from->format('M j').' – '.$prev->to->format('M j'),
        ]);
    }
}
