<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Services\AuditLogger;
use App\Services\ReportBuilder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * GET /reports/export?type=sales&from=2026-10-01&to=2026-10-10[&inline=1][&module=sales]
 * inline=1 shows the PDF in the browser (preview); otherwise it downloads.
 */
class ReportExportController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(ReportBuilder::TYPES))],
            'from' => ['required', 'date', 'before_or_equal:to'],
            'to' => ['required', 'date', 'before_or_equal:today'],
            'module' => ['nullable', Rule::in(array_keys(AuditLog::MODULES))],
            'inline' => ['nullable', 'boolean'],
        ]);

        $from = Carbon::parse($data['from']);
        $to = Carbon::parse($data['to']);
        abort_if($from->diffInDays($to) > 366, 422, 'Reports can cover at most one year.');

        // Large PDFs (long audit trails) need more than the default 128 MB / 30 s.
        @ini_set('memory_limit', '512M');
        @set_time_limit(120);

        $builder = new ReportBuilder($data['type'], $from, $to, ['module' => $data['module'] ?? null]);
        $landscape = in_array($data['type'], ['inventory', 'shifts', 'audit']);

        $pdf = Pdf::loadView('reports.pdf', [
            'type' => $data['type'],
            'title' => $builder->title(),
            'data' => $builder->build(),
            'from' => $from,
            'to' => $to,
            'store' => Setting::get('store_name'),
            'address' => Setting::get('store_address'),
            'user' => $request->user()->name,
        ])->setPaper('a4', $landscape ? 'landscape' : 'portrait')
            // Embed only the glyphs used (keeps files small) and never fetch remote resources.
            ->setOption(['isFontSubsettingEnabled' => true, 'isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Sans']);

        $filename = 'KaffeTrack_'.Str::studly($builder->title()).'_'.$from->format('Y-m-d').($from->isSameDay($to) ? '' : '_to_'.$to->format('Y-m-d')).'.pdf';

        if ($request->boolean('inline')) {
            return $pdf->stream($filename);
        }

        AuditLogger::log('reports', 'exported', "Exported {$builder->title()} report ({$from->format('M j, Y')} – {$to->format('M j, Y')}) as PDF");

        return $pdf->download($filename);
    }
}
