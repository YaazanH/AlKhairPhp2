<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin:10mm 10mm 18mm; footer:pdf-footer; }
        body { color:#17231b; font-family:dubai,sans-serif; font-size:11px; font-weight:400; }
        table { border-collapse:collapse; width:100%; }
        th,td { border:1px solid #aebbb1; padding:7px; text-align:{{ app()->isLocale('ar') ? 'right' : 'left' }}; }
        th { background:#dfece2; border-bottom:3px double #aebbb1; font-weight:700; text-align:center; }
        thead { display:table-header-group; }
        tbody tr:nth-child(even) td { background:#f1f7f2; }
        .number,.percentage { direction:ltr; text-align:center; }
        .pdf-header,.pdf-footer { background:#cfe7d6; padding:2mm 3mm; }
        .header-table { border-collapse:collapse; table-layout:fixed; width:100%; }
        .header-table td { border:0; padding:0; vertical-align:middle; }
        .header-side { width:22%; }
        .date-box { border:1px solid #aebbb1; border-radius:2mm; direction:ltr; font-size:9px; padding:2mm; text-align:center; }
        .header-copy { text-align:center; width:56%; }
        .header-title { font-size:20px; font-weight:700; }
        .header-subtitle { font-size:13px; font-weight:500; margin-top:1mm; }
        .pdf-header img { height:auto; max-height:23mm; max-width:35mm; width:auto; }
        .pdf-footer { color:#526158; font-size:9px; text-align:center; }
        .group-report + .group-report { page-break-before:always; }
    </style>
</head>
<body>
<htmlpagefooter name="pdf-footer"><div class="pdf-footer">{PAGENO} / {nbpg}</div></htmlpagefooter>
@foreach($groupReports as $report)
<section class="group-report">
    <div class="pdf-header"><table class="header-table" dir="ltr"><tr><td class="header-side"><div class="date-box">{{ $validated['date_from'] }} — {{ $validated['date_to'] }}</div></td><td class="header-copy" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}"><div class="header-title">{{ __('workflow.student_attendance.export.report_title') }}</div><div class="header-subtitle">{{ $report['group']->name }}</div></td><td class="header-side" dir="rtl">@if($logo)<img src="{{ $logo }}" alt="">@endif</td></tr></table></div>
    <table>
        <thead><tr><th>#</th><th>{{ __('course_end.table.name') }}</th><th>{{ __('workflow.student_attendance.export.student_number') }}</th><th>{{ __('workflow.student_attendance.export.percentage') }}</th></tr></thead>
        <tbody>@forelse($report['students'] as $student)<tr><td class="number">{{ $loop->iteration }}</td><td>{{ $student['name'] }}</td><td class="number">{{ $student['student_number'] }}</td><td class="percentage">{{ $student['percentage'] }}%</td></tr>@empty<tr><td colspan="4">{{ __('workflow.student_attendance.export.empty') }}</td></tr>@endforelse</tbody>
    </table>
</section>
@endforeach
</body>
</html>
