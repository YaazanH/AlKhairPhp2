<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 10mm 10mm 18mm; footer: pdf-footer; }
        body { color:#172033; font-family:dubai,sans-serif; font-size:11px; font-weight:400; }
        .heading { background:#cfe7d6; margin:0 0 4mm; table-layout:fixed; width:100%; }
        .heading-row-cell { border:0; height:18mm; padding:2mm 3mm; vertical-align:middle; }
        .heading-side { width:22%; }
        .heading-logo { text-align:right; width:30%; }
        .heading-logo img { height:18mm; max-width:35mm; width:auto; }
        .heading-copy { text-align:center; width:48%; }
        .heading-title { font-family:dubai,sans-serif; font-size:20px; font-weight:bold; }
        .heading-group { font-size:13px; font-weight:500; margin-top:1mm; }
        .heading-meta { font-size:9px; vertical-align:middle; }
        .heading-meta[dir="rtl"] { position:relative; left:-2mm; }
        .heading-meta-shell { height:18mm; table-layout:fixed; width:100%; }
        .heading-meta-shell td { border:0; height:18mm; padding:0; vertical-align:middle; }
        .heading-meta-table { border-collapse:collapse; table-layout:fixed; width:100%; }
        .heading-meta-table td { border:0; height:auto; padding:0 0 .8mm; vertical-align:middle; white-space:nowrap; }
        .heading-meta-label { font-family:dubaimedium,sans-serif; font-weight:normal; width:58%; }
        .heading-meta-value { direction:ltr; width:42%; }
        .heading-meta[dir="rtl"] .heading-meta-label { padding-left:.8mm; text-align:left; }
        .heading-meta[dir="rtl"] .heading-meta-value { padding-right:.8mm; text-align:right; }
        .heading-meta[dir="ltr"] .heading-meta-label { padding-right:.8mm; text-align:right; }
        .heading-meta[dir="ltr"] .heading-meta-value { padding-left:.8mm; text-align:left; }
        table { border-collapse:collapse; width:100%; }
        th, td { border:1px solid #b8c2ca; padding:2.5mm 2mm; }
        th { background:#dfece2; border-bottom:3px double #b8c2ca; font-weight:bold; text-align:center; }
        tbody tr:nth-child(even) td { background:#f1f7f2; }
        .pdf-footer { background:#cfe7d6; color:#526158; font-size:9pt; padding:2mm 3mm; text-align:center; }
        .number, .score, .status { text-align:center; white-space:nowrap; }
        .number { width:10mm; }
        .score { width:28mm; }
        .status { width:32mm; }
        .empty { color:#687386; padding:10mm; text-align:center; }
    </style>
</head>
<body>
<htmlpagefooter name="pdf-footer"><div class="pdf-footer" dir="ltr">{PAGENO} / {nbpg}</div></htmlpagefooter>
@forelse ($groups as $group)
    @if (! $loop->first)<pagebreak />@endif
    <table class="heading" dir="ltr"><tr>
        <td class="heading-row-cell heading-side heading-meta" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}"><table class="heading-meta-shell"><tr><td><table class="heading-meta-table"><tr><td class="heading-meta-label">{{ __('workflow.assessments.results.pdf.due_date') }}</td><td class="heading-meta-value" dir="ltr">{{ \App\Support\DateDisplay::html($assessment->due_at?->format('d-m-Y') ?? '—') }}</td></tr><tr><td class="heading-meta-label">{{ __('workflow.assessments.results.pdf.average_mark') }}</td><td class="heading-meta-value" dir="ltr">{{ $group->average_mark !== null ? number_format((float) $group->average_mark, 2) : '—' }}</td></tr></table></td></tr></table></td>
        <td class="heading-row-cell heading-copy" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}"><div class="heading-title">{{ $assessment->title }}</div><div class="heading-group">{{ $group->name }}</div></td>
        <td class="heading-row-cell heading-side heading-logo">@if($logo)<img src="{{ $logo }}" alt="" height="18mm" max-height="18mm" max-width="35mm" style="height:18mm;max-height:18mm;max-width:35mm;width:auto">@endif</td>
    </tr></table>
    <table>
        <thead><tr>
            <th class="number">{{ __('workflow.assessments.results.pdf.number') }}</th>
            <th>{{ __('workflow.assessments.results.table.headers.student') }}</th>
            <th class="score">{{ __('workflow.assessments.results.table.headers.score') }}</th>
            <th class="status">{{ __('workflow.assessments.results.table.headers.status') }}</th>
        </tr></thead>
        <tbody>
        @forelse ($group->enrollments as $enrollment)
            @php($result = $enrollment->assessmentResults->first())
            <tr>
                <td class="number">{{ $loop->iteration }}</td>
                <td>{{ $enrollment->student?->full_name }}</td>
                <td class="score">{{ number_format((float) ($result?->score ?? 0), 2) }}</td>
                <td class="status">{{ __('workflow.common.result_status.'.($result?->status ?? 'absent')) }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="empty">{{ __('workflow.assessments.results.table.empty') }}</td></tr>
        @endforelse
        </tbody>
    </table>
@empty
    <p class="empty">{{ __('workflow.assessments.results.groups.empty') }}</p>
@endforelse
</body>
</html>
