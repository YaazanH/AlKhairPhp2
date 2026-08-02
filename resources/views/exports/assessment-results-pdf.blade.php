<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 12mm 10mm; }
        body { color: #172033; font-family: dejavusanscondensed, sans-serif; font-size: 11pt; }
        h1 { font-size: 22pt; margin: 0 0 5mm; text-align: center; }
        h2 { color: #356b52; font-size: 16pt; margin: 0 0 3mm; text-align: center; }
        .meta { margin-bottom: 7mm; text-align: center; }
        .meta span { display: inline-block; margin: 0 5mm; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #b8c2ca; padding: 2.5mm 2mm; }
        th { background: #e6f3eb; font-weight: bold; }
        .number, .score, .status { text-align: center; white-space: nowrap; }
        .number { width: 10mm; }
        .score { width: 28mm; }
        .status { width: 32mm; }
        .empty { color: #687386; padding: 10mm; text-align: center; }
    </style>
</head>
<body>
@forelse ($groups as $group)
    @if (! $loop->first)<pagebreak />@endif
    <h1>{{ $assessment->title }}</h1>
    <h2>{{ $group->name }}</h2>
    <div class="meta">
        <span>{{ __('workflow.assessments.results.pdf.due_date') }}: {{ $assessment->due_at?->format('d-m-Y') ?? '—' }}</span>
        <span>{{ __('workflow.assessments.results.pdf.average_mark') }}: {{ $group->average_mark !== null ? number_format((float) $group->average_mark, 2) : '—' }}</span>
    </div>
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
                <td class="score">{{ $result?->score !== null ? number_format((float) $result->score, 2) : '—' }}</td>
                <td class="status">{{ __('workflow.common.result_status.'.($result?->status ?? 'pending')) }}</td>
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
