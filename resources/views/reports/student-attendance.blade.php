<!doctype html>
@php($archiveCourse = $archiveCourse ?? null)
<html lang="{{ app()->getLocale() }}" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin:10mm 10mm 18mm; footer:pdf-footer; }
        body { color:#17231b; font-family:dubai,sans-serif; font-size:11px; font-weight:400; }
        table { border-collapse:collapse; width:100%; }
        th,td { border:1px solid #aebbb1; padding:7px; text-align:{{ app()->isLocale('ar') ? 'right' : 'left' }}; }
        th { background:#dfece2; border-bottom:3px double #aebbb1; font-weight:bold; text-align:center; }
        thead { display:table-header-group; }
        tbody tr:nth-child(even) td { background:#f1f7f2; }
        .number,.percentage { direction:ltr; text-align:center; white-space:nowrap; }
        .pdf-header,.pdf-footer { background:#cfe7d6; padding:2mm 3mm; }
        .header-table { autosize:1; border-collapse:collapse; table-layout:fixed; width:100%; }
        .header-row-cell { border:0; height:18mm; padding:0; vertical-align:middle; }
        .header-side { width:22%; }
        .header-meta { font-size:9px; vertical-align:middle; }
        .header-meta-shell { height:18mm; table-layout:fixed; width:100%; }
        .header-meta-shell td { border:0; height:18mm; padding:0; vertical-align:middle; }
        .header-meta-table { border-collapse:collapse; direction:ltr; margin:0 auto; table-layout:fixed; width:21mm !important; }
        .header-meta-table td { border:0; height:auto; padding:0 0 .8mm; vertical-align:middle; white-space:nowrap; }
        .meta-label { font-family:dubaimedium,sans-serif; font-weight:normal; text-align:left; width:4.5mm; }
        .meta-value { position:relative; top:.35mm; direction:ltr; unicode-bidi:embed; text-align:left; width:16.5mm; }
        .header-copy { text-align:center; width:56%; }
        .header-logo { text-align:center; width:22%; }
        .header-title { font-size:20px; font-weight:bold; }
        .header-subtitle { font-size:13px; font-weight:500; margin-top:1mm; }
        .pdf-header img { height:18mm; max-width:35mm; width:auto; }
        .pdf-footer { color:#526158; font-size:9px; text-align:center; }
        .header-table-gap { height:4mm; line-height:4mm; }
        @if($archiveCourse)
        .pdf-header,.pdf-footer { background:#ead8c3; }
        th { background:rgba(241,228,212,.82); border-color:#c7ad90; border-bottom-color:#c7ad90; }
        td { border-color:#c7ad90; }
        tbody tr:nth-child(even) td { background:rgba(250,245,238,.58); }
        .pdf-footer { color:#765033; }
        @endif
    </style>
</head>
<body class="{{ $archiveCourse ? 'archive-palette' : '' }}">
<htmlpagefooter name="pdf-footer"><div class="pdf-footer" dir="ltr">{PAGENO} / {nbpg}</div></htmlpagefooter>
@foreach($groupReports as $report)
<section class="group-report">
    <div class="pdf-header"><table class="header-table" dir="ltr"><tr><td class="header-row-cell header-side header-meta" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}"><table class="header-meta-shell"><tr><td><table class="header-meta-table" dir="ltr">@if(app()->isLocale('ar'))<tr><td class="meta-value" dir="ltr">{{ \App\Support\DateDisplay::html(\Carbon\Carbon::parse($validated['date_from'])->format('d-m-Y')) }}</td><td class="meta-label">من</td></tr><tr><td class="meta-value" dir="ltr">{{ \App\Support\DateDisplay::html(\Carbon\Carbon::parse($validated['date_to'])->format('d-m-Y')) }}</td><td class="meta-label">إلى</td></tr>@else<tr><td class="meta-label">From</td><td class="meta-value" dir="ltr">{{ \App\Support\DateDisplay::html(\Carbon\Carbon::parse($validated['date_from'])->format('d-m-Y')) }}</td></tr><tr><td class="meta-label">To</td><td class="meta-value" dir="ltr">{{ \App\Support\DateDisplay::html(\Carbon\Carbon::parse($validated['date_to'])->format('d-m-Y')) }}</td></tr>@endif</table></td></tr></table></td><td class="header-row-cell header-copy" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}"><div class="header-copy-content"><div class="header-title">{{ __('workflow.student_attendance.export.report_title') }}</div><div class="header-subtitle">{{ $report['group']->name }}</div></div></td><td class="header-row-cell header-logo" dir="rtl">@if($archiveCourse)<x-exports.partials.archive-pdf-mark :course="$archiveCourse" />@elseif($logo)<img src="{{ $logo }}" alt="" height="18mm" max-height="18mm" max-width="35mm" style="height:18mm;max-height:18mm;max-width:35mm;width:auto">@endif</td></tr></table></div>
    <div class="header-table-gap">&nbsp;</div>
    <table class="report-table">
        <thead><tr><th>#</th><th>{{ __('course_end.table.name') }}</th><th>{{ __('workflow.student_attendance.export.student_number') }}</th><th>{{ __('workflow.student_attendance.export.percentage') }}</th></tr></thead>
        <tbody>@forelse($report['students'] as $student)<tr><td class="number">{{ $loop->iteration }}</td><td>{{ $student['name'] }}</td><td class="number">{{ $student['student_number'] }}</td><td class="percentage">{{ $student['percentage'] }}&nbsp;%</td></tr>@empty<tr><td colspan="4">{{ __('workflow.student_attendance.export.empty') }}</td></tr>@endforelse</tbody>
    </table>
</section>
@if (! $loop->last)<pagebreak />@endif
@endforeach
</body>
</html>
