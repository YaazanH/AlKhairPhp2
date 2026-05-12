@php
    /** @var \App\Services\FinanceReportService $service */
    $template = $report['template'];
    $language = $template->language;
    $dir = $language === \App\Models\FinanceReportTemplate::LANGUAGE_EN ? 'ltr' : 'rtl';
    $lang = $language === \App\Models\FinanceReportTemplate::LANGUAGE_EN ? 'en' : 'ar';
@endphp
<!DOCTYPE html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $template->title }}</title>
    <style>
        :root {
            color-scheme: light;
            --ink: #102316;
            --muted: #637365;
            --line: #dce6db;
            --panel: #ffffff;
            --wash: #f4f8f1;
            --accent: #0f7a3d;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #edf3ea;
            color: var(--ink);
            font-family: "Segoe UI", Tahoma, Arial, sans-serif;
            line-height: 1.55;
        }
        .page {
            width: min(1120px, calc(100% - 32px));
            margin: 24px auto;
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 24px;
            padding: 28px;
            box-shadow: 0 18px 45px rgba(16, 35, 22, 0.10);
        }
        .toolbar {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 18px;
        }
        .print-button {
            border: 0;
            border-radius: 999px;
            background: var(--accent);
            color: #fff;
            cursor: pointer;
            font-weight: 700;
            padding: 10px 18px;
        }
        .report-header {
            align-items: flex-start;
            border-bottom: 1px solid var(--line);
            display: flex;
            gap: 18px;
            justify-content: space-between;
            padding-bottom: 22px;
        }
        h1 {
            font-size: clamp(28px, 4vw, 46px);
            line-height: 1.05;
            margin: 0;
        }
        .subtitle {
            color: var(--muted);
            margin-top: 10px;
            max-width: 720px;
        }
        .meta-grid {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            margin-top: 22px;
        }
        .meta-card,
        .summary-card {
            background: var(--wash);
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 14px 16px;
        }
        .meta-label {
            color: var(--muted);
            display: block;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
        }
        .meta-value {
            display: block;
            font-size: 16px;
            font-weight: 800;
            margin-top: 5px;
        }
        .summary-grid {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            margin-top: 18px;
        }
        .summary-card strong {
            display: block;
            font-size: 20px;
            margin-top: 4px;
        }
        .table-wrap {
            border: 1px solid var(--line);
            border-radius: 18px;
            margin-top: 22px;
            overflow: hidden;
        }
        table {
            border-collapse: collapse;
            width: 100%;
        }
        th,
        td {
            border-bottom: 1px solid var(--line);
            font-size: 13px;
            padding: 11px 12px;
            text-align: start;
            vertical-align: top;
        }
        thead th {
            background: #e9f2e8;
            color: #31543b;
            font-size: 12px;
            font-weight: 800;
            white-space: nowrap;
        }
        tbody tr:last-child td { border-bottom: 0; }
        .empty {
            color: var(--muted);
            padding: 36px;
            text-align: center;
        }
        @page {
            margin: 14mm;
            size: A4 landscape;
        }
        @media print {
            body { background: #fff; }
            .page {
                border: 0;
                border-radius: 0;
                box-shadow: none;
                margin: 0;
                padding: 0;
                width: 100%;
            }
            .toolbar { display: none; }
            .table-wrap { break-inside: auto; }
            tr { break-inside: avoid; }
        }
        @media (max-width: 900px) {
            .meta-grid,
            .summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .report-header { flex-direction: column; }
        }
    </style>
</head>
<body>
    <main class="page">
        <div class="toolbar">
            <button type="button" class="print-button" onclick="window.print()">{{ __('print.layout.button') }}</button>
        </div>

        <header class="report-header">
            <div>
                <h1>{{ $template->title }}</h1>
                @if ($template->subtitle)
                    <div class="subtitle">{{ $template->subtitle }}</div>
                @endif
            </div>
            <div class="meta-card">
                <span class="meta-label">{{ $service->bilingual('Report template', 'قالب التقرير', $language) }}</span>
                <span class="meta-value">{{ $template->name }}</span>
            </div>
        </header>

        <section class="meta-grid">
            <div class="meta-card">
                <span class="meta-label">{{ $service->bilingual('Cash box', 'الصندوق', $language) }}</span>
                <span class="meta-value">{{ $report['cash_box']->name }}</span>
            </div>
            <div class="meta-card">
                <span class="meta-label">{{ $service->bilingual('Currency', 'العملة', $language) }}</span>
                <span class="meta-value">{{ $report['currency']->code }} - {{ $report['currency']->name }}</span>
            </div>
            <div class="meta-card">
                <span class="meta-label">{{ $service->bilingual('From date', 'من تاريخ', $language) }}</span>
                <span class="meta-value">{{ $report['start']->format('Y-m-d') }}</span>
            </div>
            <div class="meta-card">
                <span class="meta-label">{{ $service->bilingual('To date', 'إلى تاريخ', $language) }}</span>
                <span class="meta-value">{{ $report['end']->format('Y-m-d') }}</span>
            </div>
            @if ($template->include_exported_at)
                <div class="meta-card">
                    <span class="meta-label">{{ $service->bilingual('Exported at', 'تاريخ التصدير', $language) }}</span>
                    <span class="meta-value">{{ $report['exported_at']->format('Y-m-d H:i') }}</span>
                </div>
            @endif
        </section>

        <section class="summary-grid">
            @if ($template->include_opening_balance)
                <div class="summary-card">
                    <span class="meta-label">{{ $service->bilingual('Opening balance', 'الرصيد الافتتاحي', $language) }}</span>
                    <strong>{{ app(\App\Services\FinanceService::class)->formatCurrencyAmount($report['opening_balance'], $report['currency']) }}</strong>
                </div>
            @endif
            <div class="summary-card">
                <span class="meta-label">{{ $service->bilingual('Income', 'الإيرادات', $language) }}</span>
                <strong>{{ app(\App\Services\FinanceService::class)->formatCurrencyAmount($report['income'], $report['currency']) }}</strong>
            </div>
            <div class="summary-card">
                <span class="meta-label">{{ $service->bilingual('Expense', 'المصاريف', $language) }}</span>
                <strong>{{ app(\App\Services\FinanceService::class)->formatCurrencyAmount($report['expense'], $report['currency']) }}</strong>
            </div>
            @if ($template->include_closing_balance)
                <div class="summary-card">
                    <span class="meta-label">{{ $service->bilingual('Closing balance', 'الرصيد الختامي', $language) }}</span>
                    <strong>{{ app(\App\Services\FinanceService::class)->formatCurrencyAmount($report['closing_balance'], $report['currency']) }}</strong>
                </div>
            @endif
        </section>

        <section class="table-wrap">
            <table>
                <thead>
                    <tr>
                        @foreach ($report['columns'] as $column)
                            <th>{{ $service->ledgerColumnLabel($column, $language) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['rows'] as $row)
                        <tr>
                            @foreach ($report['columns'] as $column)
                                <td>{{ $service->ledgerColumnValue($row, $column) }}</td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($report['columns']) }}" class="empty">{{ __('finance.empty.no_transactions') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    </main>
</body>
</html>
