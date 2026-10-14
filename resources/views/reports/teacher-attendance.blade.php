<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin:0 10mm 18mm; margin-header:10mm; header:pdf-header; footer:pdf-footer; }
        body { color:#17231b; font-family:dubai,sans-serif; font-size:11px; font-weight:400; }
        table { border-collapse:collapse; width:100%; }
        th,td { border:1px solid #aebbb1; padding:7px; text-align:{{ app()->isLocale('ar') ? 'right' : 'left' }}; }
        th { background:#dfece2; border-bottom:3px double #aebbb1; font-weight:bold; text-align:center; }
        thead { display:table-header-group; }
        tbody tr:nth-child(even) td { background:#f1f7f2; }
        .pdf-header,.pdf-footer { background:#cfe7d6; padding:2mm 3mm; }
        .header-table { autosize:1; border-collapse:collapse; table-layout:fixed; width:100%; }
        .header-row-cell { border:0; height:18mm; padding:0; vertical-align:middle; }
        .header-side { width:22%; }
        .header-meta { font-size:9px; vertical-align:middle; }
        .header-meta[dir="rtl"] { position:relative; left:-2mm; }
        .header-meta-shell { height:18mm; table-layout:fixed; width:100%; }
        .header-meta-shell td { border:0; height:18mm; padding:0; vertical-align:middle; }
        .header-meta-table { border-collapse:collapse; direction:ltr; margin-left:17.5mm; table-layout:fixed; width:17mm; }
        .header-meta-table td { border:0; height:auto; padding:0 0 .8mm; vertical-align:middle; white-space:nowrap; }
        .meta-label { font-family:dubaimedium,sans-serif; font-weight:normal; width:3mm; }
        .meta-value { direction:ltr; unicode-bidi:embed; padding-top:.55mm !important; padding-bottom:.25mm !important; text-align:left; vertical-align:middle; width:14mm; }
        .header-meta[dir="rtl"] .meta-label { text-align:left; }
        .header-meta[dir="ltr"] .meta-label { padding-right:.8mm; text-align:right; }
        .header-meta[dir="ltr"] .meta-value { padding-left:.8mm; text-align:left; }
        .header-copy { text-align:center; width:48%; }
        .header-copy-content { position:relative; left:-5mm; }
        .header-logo { width:30%; }
        .header-title { font-size:20px; font-weight:bold; }
        .header-subtitle { font-size:13px; font-weight:500; margin-top:1mm; }
        .pdf-header img { height:18mm; max-width:35mm; width:auto; }
        .pdf-footer { color:#526158; font-size:9px; text-align:center; }
    </style>
</head>
<body>
<htmlpageheader name="pdf-header"><div class="pdf-header"><table class="header-table" dir="ltr"><tr><td class="header-row-cell header-side header-meta" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}"><table class="header-meta-shell"><tr><td><table class="header-meta-table" dir="ltr">@if(app()->isLocale('ar'))<tr><td class="meta-value" dir="ltr">{{ \App\Support\DateDisplay::html(\Carbon\Carbon::parse($validated['date_from'])->format('d-m-Y')) }}</td><td class="meta-label">من</td></tr><tr><td class="meta-value" dir="ltr">{{ \App\Support\DateDisplay::html(\Carbon\Carbon::parse($validated['date_to'])->format('d-m-Y')) }}</td><td class="meta-label">إلى</td></tr>@else<tr><td class="meta-label">From</td><td class="meta-value" dir="ltr">{{ \App\Support\DateDisplay::html(\Carbon\Carbon::parse($validated['date_from'])->format('d-m-Y')) }}</td></tr><tr><td class="meta-label">To</td><td class="meta-value" dir="ltr">{{ \App\Support\DateDisplay::html(\Carbon\Carbon::parse($validated['date_to'])->format('d-m-Y')) }}</td></tr>@endif</table></td></tr></table></td><td class="header-row-cell header-copy" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}"><div class="header-copy-content"><div class="header-title">{{ __('workflow.teacher_attendance.export.report_title') }}</div><div class="header-subtitle">{{ $course?->name }}</div></div></td><td class="header-row-cell header-logo" dir="rtl">@if($logo)<img src="{{ $logo }}" alt="" height="18mm" max-height="18mm" max-width="35mm" style="height:18mm;max-height:18mm;max-width:35mm;width:auto">@endif</td></tr></table></div></htmlpageheader>
<htmlpagefooter name="pdf-footer"><div class="pdf-footer" dir="ltr">{PAGENO} / {nbpg}</div></htmlpagefooter>
<table class="report-table">
    <thead><tr><th>#</th><th>{{ __('course_end.table.name') }}</th><th>{{ __('workflow.teacher_attendance.export.role') }}</th><th>{{ __('workflow.teacher_attendance.export.percentage') }}</th></tr></thead>
    <tbody>@foreach($teachers as $teacher)<tr><td>{{ $loop->iteration }}</td><td>{{ $teacher['name'] }}</td><td>{{ $teacher['role'] }}</td><td>{{ $teacher['percentage'] }}%</td></tr>@endforeach</tbody>
</table>
</body>
</html>
