<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin:25mm 10mm 18mm; header:pdf-header; footer:pdf-footer; }
        body { color:#17231b; font-family:dubai,sans-serif; font-size:11px; font-weight:400; }
        h1,h2,.meta { text-align:center; }
        h1 { font-size:24px; font-weight:700; margin:0; }
        h2 { font-size:15px; font-weight:500; margin:5px 0; }
        .meta { font-weight:300; margin:12px; }
        table { border-collapse:collapse; width:100%; }
        th,td { border:1px solid #aebbb1; padding:7px; text-align:right; }
        th { background:#e9f1e9; font-weight:700; }
        thead { display:table-header-group; }
        tbody tr:nth-child(even) td { background:#f1f7f2; }
        .pdf-header,.pdf-footer { background:#e6f3eb; padding:2mm 3mm; }
        .pdf-header img { height:auto; max-height:23mm; max-width:35mm; width:auto; }
        .pdf-footer { color:#526158; font-size:9px; text-align:center; }
    </style>
</head>
<body>
<htmlpageheader name="pdf-header"><div class="pdf-header">@if($logo)<img src="{{ $logo }}" alt="">@endif</div></htmlpageheader>
<htmlpagefooter name="pdf-footer"><div class="pdf-footer">{PAGENO} / {nbpg}</div></htmlpagefooter>
<h1>حضور المشرفين</h1>
<h2>{{ $course?->name }}</h2>
<div class="meta">{{ $validated['date_from'] }} — {{ $validated['date_to'] }}</div>
<table>
    <thead><tr><th>#</th><th>{{ __('course_end.table.name') }}</th><th>{{ __('workflow.teacher_attendance.export.role') }}</th><th>{{ __('workflow.teacher_attendance.export.percentage') }}</th></tr></thead>
    <tbody>@foreach($teachers as $teacher)<tr><td>{{ $loop->iteration }}</td><td>{{ $teacher['name'] }}</td><td>{{ $teacher['role'] }}</td><td>{{ $teacher['percentage'] }}%</td></tr>@endforeach</tbody>
</table>
</body>
</html>
