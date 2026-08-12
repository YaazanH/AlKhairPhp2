<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 25mm 10mm 18mm; header: pdf-header; footer: pdf-footer; }
        body { color: #172033; font-family: dubai, sans-serif; font-size: 11pt; }
        .heading { display: table; margin: 0 0 5mm; width: 100%; }
        .heading-title, .heading-group { display: table-cell; font-size: 22pt; font-weight: bold; width: 50%; }
        .heading-title { text-align: right; }
        .heading-group { text-align: left; }
        .meta { border: 0; margin-bottom: 7mm; table-layout: fixed; }
        .meta td { border: 0; padding: 0 0 5mm; width: 50%; }
        .meta-date { text-align: right; }
        .meta-average { text-align: left; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #b8c2ca; padding: 2.5mm 2mm; }
        th { background: #e6f3eb; font-weight: bold; }
        tbody tr:nth-child(even) td { background: #f1f7f2; }
        .pdf-header,.pdf-footer { background:#e6f3eb; padding:2mm 3mm; }
        .pdf-header img { height:auto; max-height:23mm; max-width:35mm; width:auto; }
        .pdf-footer { color:#526158; font-size:9pt; text-align:center; }
        .number, .score, .status { text-align: center; white-space: nowrap; }
        .number { width: 10mm; }
        .score { width: 28mm; }
        .status { width: 32mm; }
        .empty { color: #687386; padding: 10mm; text-align: center; }
    </style>
</head>
<body>
<htmlpageheader name="pdf-header"><div class="pdf-header">@if($logo)<img src="{{ $logo }}" alt="">@endif</div></htmlpageheader>
<htmlpagefooter name="pdf-footer"><div class="pdf-footer">{PAGENO} / {nbpg}</div></htmlpagefooter>
@forelse ($groups as $group)
    @if (! $loop->first)<pagebreak />@endif
    <div class="heading">
        <div class="heading-title">{{ $assessment->title }}</div>
        <div class="heading-group">{{ $group->name }}</div>
    </div>
    <table class="meta"><tr>
        <td class="meta-date">{{ __('workflow.assessments.results.pdf.due_date') }}: {{ $assessment->due_at?->format('d-m-Y') ?? '—' }}</td>
        <td class="meta-average">{{ __('workflow.assessments.results.pdf.average_mark') }}: {{ $group->average_mark !== null ? number_format((float) $group->average_mark, 2) : '—' }}</td>
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
