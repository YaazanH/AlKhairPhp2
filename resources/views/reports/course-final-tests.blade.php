<!doctype html>
<html lang="ar" dir="rtl"><head><meta charset="utf-8"><style>
body{font-family:dubai,sans-serif;color:#17231b;font-size:11px;font-weight:400}h1{text-align:center;font-size:24px;font-weight:700;margin:0}h2{text-align:center;font-size:15px;margin:5px 0 12px;font-weight:500}.meta{text-align:center;margin-bottom:14px;color:#4b5d50;font-weight:300}table{width:100%;border-collapse:collapse}th,td{border:1px solid #aebbb1;padding:7px;text-align:right}th{background:#e9f1e9;font-weight:700}thead{display:table-header-group}
</style></head><body>
<h1>بيان الأجزاء المسبورة</h1><h2>{{ $course->name }}</h2>
<div class="meta">{{ now()->format('d-m-Y') }} — {{ __('course_end.highlights.final_tests') }}: {{ number_format($rows->count()) }}</div>
<table><thead><tr><th>#</th><th>{{ __('course_end.table.name') }}</th><th>{{ __('course_end.table.juz') }}</th><th>{{ __('course_end.table.mark') }}</th></tr></thead><tbody>
@foreach($rows as $row)<tr><td>{{ $loop->iteration }}</td><td>{{ $row['name'] }}</td><td>{{ $row['juz'] }}</td><td>{{ \App\Support\PercentageFormatter::format($row['mark']) }}</td></tr>@endforeach
</tbody></table></body></html>
