<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 8mm 8mm 16mm; footer: report-footer; }
        body { color:#172033; font-family:dubai,sans-serif; font-size:9.5pt; }
        .header { background:#dcefe3; border-radius:3mm; margin-bottom:4mm; padding:4mm; }
        .header-table, .report-table, .group-table { border-collapse:collapse; width:100%; }
        .header-table td { border:0; padding:0; vertical-align:middle; }
        .logo { text-align:{{ app()->isLocale('ar') ? 'left' : 'right' }}; width:35mm; }
        .logo img { max-height:18mm; max-width:32mm; }
        h1 { font-size:20pt; line-height:1.15; margin:0; }
        .organisation { color:#526158; font-size:11pt; margin-top:1mm; }
        .description { color:#526158; margin:2mm 0 0; }
        .meta { color:#526158; font-size:8.5pt; margin-top:2mm; }
        .summary { margin:0 0 4mm; width:100%; }
        .summary-item { background:#f1f7f2; border:1px solid #c9d8ce; display:inline-block; margin:0 0 2mm 2mm; padding:2mm 3mm; }
        .summary-label { color:#526158; font-size:8pt; }
        .summary-value { font-size:12pt; font-weight:bold; }
        .section-title { font-size:12pt; font-weight:bold; margin:4mm 0 2mm; }
        .report-table th, .report-table td, .group-table th, .group-table td { border:1px solid #c4cec7; padding:1.6mm 1.4mm; }
        .report-table th, .group-table th { background:#dfece2; font-weight:bold; text-align:center; }
        .report-table tbody tr:nth-child(even) td, .group-table tbody tr:nth-child(even) td { background:#f5f8f6; }
        .empty { color:#687386; padding:8mm; text-align:center; }
        .footer { background:#dcefe3; color:#526158; font-size:8pt; padding:2mm; text-align:center; }
    </style>
</head>
<body>
<htmlpagefooter name="report-footer"><div class="footer">{{ __('report_designer.exports.generated_at', ['date' => now()->format('Y-m-d H:i')]) }} &nbsp; | &nbsp; {PAGENO} / {nbpg}</div></htmlpagefooter>

<div class="header">
    <table class="header-table"><tr>
        <td>
            <h1>{{ $definition->name }}</h1>
            <div class="organisation">{{ $organisationName }} &middot; {{ $source['label'] }}</div>
            @if(filled($definition->description))<div class="description">{{ $definition->description }}</div>@endif
            <div class="meta">{{ __('report_designer.exports.matching_rows', ['count' => $result['total']]) }}</div>
        </td>
        <td class="logo">@if($logo)<img src="{{ $logo }}" alt="">@endif</td>
    </tr></table>
</div>

@if($result['calculations'] !== [])
    <div class="summary">
        @foreach($result['calculations'] as $calculation)
            <div class="summary-item">
                <div class="summary-label">{{ $calculation['label'] }}</div>
                <div class="summary-value">{{ $calculation['value'] ?? '—' }}</div>
            </div>
        @endforeach
    </div>
@endif

@if($result['grouping'])
    <div class="section-title">{{ __('report_designer.grouping.title', ['field' => $result['grouping']['label']]) }}</div>
    <table class="group-table">
        <thead><tr>@foreach($result['grouping']['columns'] as $column)<th>{{ $column }}</th>@endforeach</tr></thead>
        <tbody>
        @foreach($result['grouping']['rows'] as $row)
            <tr>@foreach(array_keys($result['grouping']['columns']) as $key)<td>{{ filled($row[$key] ?? null) ? $row[$key] : '—' }}</td>@endforeach</tr>
        @endforeach
        </tbody>
    </table>
@endif

<div class="section-title">{{ __('report_designer.exports.details') }}</div>
<table class="report-table">
    <thead><tr>@foreach($result['columns'] as $column)<th>{{ $column['label'] }}</th>@endforeach</tr></thead>
    <tbody>
    @forelse($result['rows'] as $row)
        <tr>@foreach(array_keys($result['columns']) as $key)<td>{{ filled($row[$key] ?? null) ? $row[$key] : '—' }}</td>@endforeach</tr>
    @empty
        <tr><td colspan="{{ max(1, count($result['columns'])) }}" class="empty">{{ __('report_designer.preview.empty') }}</td></tr>
    @endforelse
    </tbody>
</table>
</body>
</html>
