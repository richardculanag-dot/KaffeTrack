<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} — {{ $store }}</title>
    <style>
        /* DomPDF supports CSS 2.1 + a little CSS3: tables and floats only, no flex/grid. */
        @page { margin: 26mm 14mm 18mm 14mm; }
        * { font-family: 'DejaVu Sans', sans-serif; }
        body { font-size: 9.5px; color: #1a2520; line-height: 1.4; }
        header { position: fixed; top: -19mm; left: 0; right: 0; height: 15mm; border-bottom: 2px solid #2f6b4f; }
        footer { position: fixed; bottom: -12mm; left: 0; right: 0; height: 8mm; border-top: 1px solid #dce5de; color: #6b7d71; font-size: 8px; padding-top: 2mm; }
        /* Page numbers via CSS counters (no need to enable DomPDF's inline PHP). */
        .pagenum { float: right; }
        .pagenum:after { content: "Page " counter(page); }
        .brand { float: left; }
        .brand .name { font-size: 15px; font-weight: bold; color: #173a2c; }
        .brand .sub { color: #6b7d71; font-size: 8.5px; }
        .meta { float: right; text-align: right; color: #4f6056; font-size: 8.5px; }
        .meta .title { font-size: 12px; font-weight: bold; color: #1a2520; }
        h2 { font-size: 11.5px; color: #173a2c; margin: 16px 0 6px; padding-bottom: 3px; border-bottom: 1px solid #dce5de; }
        h3 { font-size: 10px; margin: 10px 0 4px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #eaf1eb; color: #3a4840; text-align: left; font-weight: bold; padding: 5px 6px; font-size: 8.5px; }
        td { padding: 4px 6px; border-bottom: 1px solid #eef3ef; vertical-align: top; }
        tr.total td { border-top: 1.5px solid #1a2520; border-bottom: 0; font-weight: bold; }
        tr.sub td { background: #f5f7f3; font-weight: bold; }
        .r { text-align: right; } .c { text-align: center; }
        .muted { color: #6b7d71; } .small { font-size: 8px; }
        .pos { color: #275840; } .neg { color: #93342c; } .warn { color: #85520b; }
        .kpis { width: 100%; margin: 4px 0 6px; }
        .kpis td { border: 1px solid #dce5de; padding: 7px 8px; width: 25%; }
        .kpis .label { color: #6b7d71; font-size: 8px; }
        .kpis .value { font-size: 13px; font-weight: bold; color: #173a2c; }
        .bar { height: 7px; background: #3f845c; }
        .note { background: #f5f7f3; border-left: 3px solid #2f6b4f; padding: 6px 8px; margin: 8px 0; color: #3a4840; }
        .pill { padding: 1px 5px; border-radius: 6px; font-size: 7.5px; font-weight: bold; }
        .pill-red { background: #fbefed; color: #93342c; } .pill-amber { background: #fdf6e7; color: #85520b; } .pill-green { background: #e1eee4; color: #214635; }
        .page-break { page-break-before: always; }
        .signatures { margin-top: 28px; width: 100%; }
        .signatures td { border: 0; width: 33%; padding-top: 26px; }
        .signatures .line { border-top: 1px solid #1a2520; padding-top: 3px; font-size: 8px; color: #4f6056; }
    </style>
</head>
<body>
<header>
    <div class="brand">
        <div class="name">{{ $store }}</div>
        <div class="sub">KaffeTrack · Café POS & Inventory · {{ $address }}</div>
    </div>
    <div class="meta">
        <div class="title">{{ $title }}</div>
        <div>{{ $from->isSameDay($to) ? $from->format('F j, Y') : $from->format('M j, Y').' – '.$to->format('M j, Y') }}</div>
    </div>
</header>
<footer>
    <span class="pagenum"></span>
    Generated {{ now()->format('M j, Y g:i A') }} by {{ $user }} · KaffeTrack
</footer>
<main>
    @include('reports.'.$type, $data)

    <table class="signatures">
        <tr>
            <td><div class="line">Prepared by: {{ $user }}</div></td>
            <td></td>
            <td><div class="line">Reviewed / approved by</div></td>
        </tr>
    </table>
</main>
</body>
</html>
