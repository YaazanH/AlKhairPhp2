<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <style>
        body { color:#17231b; font-family:dubai,sans-serif; font-size:11px; font-weight:400; }
        h1,h2,.meta { text-align:center; }
        h1 { font-size:24px; font-weight:700; margin:0; }
        h2 { font-size:15px; font-weight:500; margin:5px 0; }
        .meta { font-weight:300; margin:12px; }
        table { border-collapse:collapse; width:100%; }
        th,td { border:1px solid #aebbb1; padding:7px; text-align:right; }
        th { background:#e9f1e9; font-weight:700; }
        thead { display:table-header-group; }
    </style>
</head>
<body>
<h1>حضور المشرفين</h1>
<h2>{{ $course?->name }}</h2>
<div class="meta">{{ $validated['date_from'] }} — {{ $validated['date_to'] }}</div>
<table>
    <thead><tr><th>#</th><th>{{ __('course_end.table.name') }}</th><th>{{ __('workflow.teacher_attendance.export.role') }}</th><th>{{ __('workflow.teacher_attendance.export.percentage') }}</th></tr></thead>
    <tbody>@foreach($teachers as $teacher)<tr><td>{{ $loop->iteration }}</td><td>{{ $teacher['name'] }}</td><td>{{ $teacher['role'] }}</td><td>{{ $teacher['percentage'] }}%</td></tr>@endforeach</tbody>
</table>
</body>
</html>
