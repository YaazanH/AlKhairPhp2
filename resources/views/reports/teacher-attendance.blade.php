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
        .header-table td { border:0; padding:0; vertical-align:middle; }
        .header-side { width:22%; }
        .date-box { font-size:9px; margin-left:7mm; width:32mm; }
        .date-table { border-collapse:collapse; table-layout:fixed; width:32mm; }
        .date-table td { border:0; padding:0; white-space:nowrap; }
        .date-label { width:8mm; font-family:dubaimedium,sans-serif; font-weight:normal; text-align:{{ app()->isLocale('ar') ? 'left' : 'right' }}; }
        .date-spacer { width:3mm; }
        .date-value { width:21mm; direction:ltr; unicode-bidi:embed; text-align:{{ app()->isLocale('ar') ? 'right' : 'left' }}; }
        .header-copy { text-align:center; width:56%; }
        .header-title { font-size:22px; font-weight:bold; }
        .header-subtitle { font-family:dubaimedium,sans-serif; font-size:13px; font-weight:normal; margin-top:1.75mm; }
        .pdf-header img { height:18mm; max-width:35mm; width:auto; }
        .pdf-footer { color:#526158; font-size:9px; text-align:center; }
    </style>
</head>
<body>
<htmlpageheader name="pdf-header"><div class="pdf-header"><table class="header-table" dir="ltr"><tr><td class="header-side"><div class="date-box"><table class="date-table" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}"><tr><td class="date-label">{{ app()->isLocale('ar') ? 'من' : 'From' }}</td><td class="date-spacer">&nbsp;</td><td class="date-value" dir="ltr">{{ \Carbon\Carbon::parse($validated['date_from'])->format('d-m-Y') }}</td></tr><tr><td class="date-label">{{ app()->isLocale('ar') ? 'إلى' : 'To' }}</td><td class="date-spacer">&nbsp;</td><td class="date-value" dir="ltr">{{ \Carbon\Carbon::parse($validated['date_to'])->format('d-m-Y') }}</td></tr></table></div></td><td class="header-copy" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}"><div class="header-title">{{ __('workflow.teacher_attendance.export.report_title') }}</div><div class="header-subtitle">{{ $course?->name }}</div></td><td class="header-side" dir="rtl">@if($logo)<img src="{{ $logo }}" alt="" height="18mm" max-height="18mm" max-width="35mm" style="height:18mm;max-height:18mm;max-width:35mm;width:auto">@endif</td></tr></table></div></htmlpageheader>
<htmlpagefooter name="pdf-footer"><div class="pdf-footer" dir="ltr">{PAGENO} / {nbpg}</div></htmlpagefooter>
<table class="report-table">
    <thead><tr><th>#</th><th>{{ __('course_end.table.name') }}</th><th>{{ __('workflow.teacher_attendance.export.role') }}</th><th>{{ __('workflow.teacher_attendance.export.percentage') }}</th></tr></thead>
    <tbody>@foreach($teachers as $teacher)<tr><td>{{ $loop->iteration }}</td><td>{{ $teacher['name'] }}</td><td>{{ $teacher['role'] }}</td><td>{{ $teacher['percentage'] }}%</td></tr>@endforeach</tbody>
</table>
</body>
</html>
