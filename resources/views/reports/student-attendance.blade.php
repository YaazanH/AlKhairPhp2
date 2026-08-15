<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin:31mm 10mm 18mm; header:pdf-header; footer:pdf-footer; }
        body { color:#17231b; font-family:dubai,sans-serif; font-size:11px; font-weight:400; }
        .meta { font-weight:300; margin:12px; }
        table { border-collapse:collapse; width:100%; }
        th,td { border:1px solid #aebbb1; padding:7px; text-align:{{ app()->isLocale('ar') ? 'right' : 'left' }}; }
        th { background:#e9f1e9; font-weight:700; }
        thead { display:table-header-group; }
        tbody tr:nth-child(even) td { background:#f1f7f2; }
        .number,.percentage { direction:ltr; text-align:center; }
        .pdf-header,.pdf-footer { background:#e6f3eb; padding:2mm 3mm; }
        .header-table { border-collapse:collapse; table-layout:fixed; width:100%; }
        .header-table td { border:0; padding:0; vertical-align:middle; }
        .header-side { width:22%; }
        .header-copy { text-align:center; width:56%; }
        .header-title { font-size:20px; font-weight:700; }
        .header-subtitle { font-size:13px; font-weight:500; margin-top:1mm; }
        .pdf-header img { height:auto; max-height:23mm; max-width:35mm; width:auto; }
        .pdf-footer { color:#526158; font-size:9px; text-align:center; }
    </style>
</head>
<body>
<htmlpageheader name="pdf-header"><div class="pdf-header"><table class="header-table" dir="ltr"><tr><td class="header-side"></td><td class="header-copy" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}"><div class="header-title">{{ __('workflow.student_attendance.export.report_title') }}</div><div class="header-subtitle">{{ $group->course?->name }} · {{ $group->name }}</div></td><td class="header-side" dir="rtl">@if($logo)<img src="{{ $logo }}" alt="">@endif</td></tr></table></div></htmlpageheader>
<htmlpagefooter name="pdf-footer"><div class="pdf-footer">{PAGENO} / {nbpg}</div></htmlpagefooter>
<div class="meta">{{ $validated['date_from'] }} — {{ $validated['date_to'] }}</div>
<table>
    <thead><tr><th>#</th><th>{{ __('course_end.table.name') }}</th><th>{{ __('workflow.student_attendance.export.student_number') }}</th><th>{{ __('workflow.student_attendance.export.percentage') }}</th></tr></thead>
    <tbody>@forelse($students as $student)<tr><td class="number">{{ $loop->iteration }}</td><td>{{ $student['name'] }}</td><td class="number">{{ $student['student_number'] }}</td><td class="percentage">{{ $student['percentage'] }}%</td></tr>@empty<tr><td colspan="4">{{ __('workflow.student_attendance.export.empty') }}</td></tr>@endforelse</tbody>
</table>
</body>
</html>
