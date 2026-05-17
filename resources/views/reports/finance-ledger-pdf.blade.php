@php
    /** @var \App\Services\FinanceReportService $service */
    $template = $report['template'];
    $language = $template['language'] ?? \App\Models\FinanceReportTemplate::LANGUAGE_BOTH;
    $dir = $language === \App\Models\FinanceReportTemplate::LANGUAGE_EN ? 'ltr' : 'rtl';
    $lang = $language === \App\Models\FinanceReportTemplate::LANGUAGE_EN ? 'en' : 'ar';
@endphp
<!DOCTYPE html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $template['title'] }}</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #edf3ea;
        }
        .ledger-report-shell {
            margin: 24px auto;
            width: min(1120px, calc(100% - 32px));
        }
        .ledger-report-toolbar {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 18px;
        }
        .ledger-report-print-button {
            border: 0;
            border-radius: 999px;
            background: #0f7a3d;
            color: #fff;
            cursor: pointer;
            font-weight: 700;
            padding: 10px 18px;
        }
        @page {
            margin: 14mm;
            size: A4 landscape;
        }
        @media print {
            body {
                background: #fff;
            }
            .ledger-report-shell {
                margin: 0;
                width: 100%;
            }
            .ledger-report-toolbar {
                display: none;
            }
            .ledger-report-doc__page {
                border: 0 !important;
                border-radius: 0 !important;
                box-shadow: none !important;
            }
            .ledger-report-doc__table-wrap {
                break-inside: auto;
            }
            .ledger-report-doc tr {
                break-inside: avoid;
            }
        }
    </style>
</head>
<body>
    <main class="ledger-report-shell">
        <div class="ledger-report-toolbar">
            <button type="button" class="ledger-report-print-button" onclick="window.print()">{{ __('print.layout.button') }}</button>
        </div>

        @include('reports.partials.finance-ledger-document', ['previewMode' => false, 'report' => $report, 'service' => $service])
    </main>
</body>
</html>
