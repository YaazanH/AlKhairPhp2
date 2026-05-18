@php
    /** @var \App\Services\FinanceReportService $service */
    $template = $report['template'];
    $language = $template['language'] ?? \App\Models\FinanceReportTemplate::LANGUAGE_BOTH;
    $dir = $language === \App\Models\FinanceReportTemplate::LANGUAGE_EN ? 'ltr' : 'rtl';
    $lang = $language === \App\Models\FinanceReportTemplate::LANGUAGE_EN ? 'en' : 'ar';
    $textAlign = $dir === 'rtl' ? 'right' : 'left';
    $brandAlign = $dir === 'rtl' ? 'left' : 'right';
    $shapeType = $template['shape_type'] ?? null;
    $shapeColor = $template['shape_color'] ?? '#0f7a3d';
    $shapeOpacity = (float) ($template['shape_opacity'] ?? 0.12);
    $backgroundImageSrc = $template['background_image_pdf_src'] ?? null;
    $logoImageSrc = $template['logo_image_pdf_src'] ?? null;

    $metaCards = [
        [
            'label' => $service->bilingual('Cash box', 'الصندوق', $language),
            'value' => data_get($report, 'cash_box.name'),
        ],
        [
            'label' => $service->bilingual('Currency', 'العملة', $language),
            'value' => trim((string) data_get($report, 'currency.code').' - '.(string) data_get($report, 'currency.name')),
        ],
        [
            'label' => $service->bilingual('From date', 'من تاريخ', $language),
            'value' => $report['start'] ?? '',
        ],
        [
            'label' => $service->bilingual('To date', 'إلى تاريخ', $language),
            'value' => $report['end'] ?? '',
        ],
        [
            'label' => $service->bilingual('Report date', 'تاريخ التقرير', $language),
            'value' => $report['report_date'] ?? '',
        ],
    ];

    if (($template['show_issuer_name'] ?? false) && ! empty($report['issuer_name'])) {
        $metaCards[] = [
            'label' => $service->bilingual('Issued by', 'أصدر بواسطة', $language),
            'value' => $report['issuer_name'],
        ];
    }

    if (($template['include_exported_at'] ?? false) && ! empty($report['exported_at'])) {
        $metaCards[] = [
            'label' => $service->bilingual('Exported at', 'تاريخ التصدير', $language),
            'value' => \Illuminate\Support\Carbon::parse($report['exported_at'])->format('Y-m-d H:i'),
        ];
    }

    $summaryCards = [];

    if ($template['include_opening_balance'] ?? false) {
        $summaryCards[] = [
            'label' => $service->bilingual('Opening balance', 'الرصيد الافتتاحي', $language),
            'value' => data_get($report, 'formatted.opening_balance'),
        ];
    }

    $summaryCards[] = [
        'label' => $service->bilingual('Income', 'الإيرادات', $language),
        'value' => data_get($report, 'formatted.income'),
    ];
    $summaryCards[] = [
        'label' => $service->bilingual('Expense', 'المصاريف', $language),
        'value' => data_get($report, 'formatted.expense'),
    ];

    if ($template['include_closing_balance'] ?? false) {
        $summaryCards[] = [
            'label' => $service->bilingual('Closing balance', 'الرصيد الختامي', $language),
            'value' => data_get($report, 'formatted.closing_balance'),
        ];
    }
@endphp
<!DOCTYPE html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <title>{{ $template['title'] }}</title>
    <style>
        @page {
            margin: 14mm;
            size: A4 landscape;
        }

        * {
            box-sizing: border-box;
        }

        body {
            color: #102316;
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            line-height: 1.45;
            margin: 0;
        }

        .ledger-report {
            position: relative;
        }

        .ledger-report__background {
            bottom: 0;
            left: 0;
            opacity: 0.08;
            position: absolute;
            right: 0;
            top: 0;
            z-index: 0;
        }

        .ledger-report__background img {
            height: 100%;
            width: 100%;
        }

        .ledger-report__shape {
            position: absolute;
            z-index: 0;
        }

        .ledger-report__shape--rectangle {
            background: {{ $shapeColor }};
            height: 72px;
            opacity: {{ $shapeOpacity }};
            top: 0;
            width: 220px;
            {{ $dir === 'rtl' ? 'left: 0;' : 'right: 0;' }}
        }

        .ledger-report__shape--circle {
            background: {{ $shapeColor }};
            border-radius: 999px;
            height: 110px;
            opacity: {{ $shapeOpacity }};
            top: -16px;
            width: 110px;
            {{ $dir === 'rtl' ? 'left: 0;' : 'right: 0;' }}
        }

        .ledger-report__shape--triangle {
            background: transparent;
            border-bottom: 120px solid {{ $shapeColor }};
            border-left: 65px solid transparent;
            border-right: 65px solid transparent;
            height: 0;
            opacity: {{ $shapeOpacity }};
            top: 0;
            width: 0;
            {{ $dir === 'rtl' ? 'left: 0;' : 'right: 0;' }}
        }

        .ledger-report__content {
            position: relative;
            z-index: 1;
        }

        .ledger-report__header {
            border-bottom: 1px solid #dce6db;
            padding-bottom: 16px;
            width: 100%;
        }

        .ledger-report__header-table,
        .ledger-report__meta-table,
        .ledger-report__summary-table,
        .ledger-report__footer-table {
            border-collapse: separate;
            border-spacing: 0;
            width: 100%;
        }

        .ledger-report__brand {
            text-align: {{ $brandAlign }};
            vertical-align: top;
            width: 180px;
        }

        .ledger-report__logo {
            border: 1px solid #dce6db;
            border-radius: 18px;
            display: inline-block;
            padding: 10px;
        }

        .ledger-report__logo img {
            max-height: 70px;
            max-width: 150px;
        }

        .ledger-report__eyebrow {
            color: #637365;
            font-size: 10px;
            font-weight: 700;
        }

        .ledger-report__title {
            font-size: 28px;
            font-weight: 700;
            margin: 8px 0 0;
        }

        .ledger-report__copy,
        .ledger-report__subtitle,
        .ledger-report__footer-copy {
            color: #516255;
            margin-top: 6px;
            white-space: pre-line;
        }

        .ledger-report__meta-table,
        .ledger-report__summary-table {
            border-spacing: 8px;
            margin-top: 14px;
        }

        .ledger-report__meta-card,
        .ledger-report__summary-card {
            background: #f4f8f1;
            border: 1px solid #dce6db;
            border-radius: 16px;
            padding: 10px 12px;
            vertical-align: top;
            width: 25%;
        }

        .ledger-report__meta-label {
            color: #637365;
            display: block;
            font-size: 10px;
            font-weight: 700;
        }

        .ledger-report__meta-value {
            display: block;
            font-size: 13px;
            font-weight: 700;
            margin-top: 4px;
        }

        .ledger-report__summary-card .ledger-report__meta-value {
            font-size: 15px;
        }

        .ledger-report__custom-text {
            background: #fbfdf9;
            border: 1px dashed #dce6db;
            border-radius: 16px;
            margin-top: 14px;
            padding: 12px 14px;
            white-space: pre-line;
        }

        .ledger-report__table {
            border-collapse: collapse;
            margin-top: 16px;
            width: 100%;
        }

        .ledger-report__table thead {
            display: table-header-group;
        }

        .ledger-report__table tr {
            page-break-inside: avoid;
        }

        .ledger-report__table th,
        .ledger-report__table td {
            border: 1px solid #dce6db;
            font-size: 11px;
            padding: 8px 9px;
            text-align: {{ $textAlign }};
            vertical-align: top;
        }

        .ledger-report__table th {
            background: #e9f2e8;
            color: #31543b;
            font-weight: 700;
        }

        .ledger-report__empty {
            color: #637365;
            text-align: center;
        }

        .ledger-report__footer {
            border-top: 1px solid #dce6db;
            margin-top: 16px;
            padding-top: 12px;
        }

        .ledger-report__page-number {
            color: #31543b;
            font-size: 11px;
            font-weight: 700;
            text-align: {{ $brandAlign }};
        }
    </style>
</head>
<body>
    <div class="ledger-report">
        @if ($backgroundImageSrc)
            <div class="ledger-report__background">
                <img src="{{ $backgroundImageSrc }}" alt="">
            </div>
        @endif

        @if ($shapeType)
            <div class="ledger-report__shape ledger-report__shape--{{ $shapeType }}"></div>
        @endif

        <div class="ledger-report__content">
            <table class="ledger-report__header-table ledger-report__header">
                <tr>
                    <td>
                        <div class="ledger-report__eyebrow">{{ $template['name'] }}</div>
                        <div class="ledger-report__title">{{ $template['title'] }}</div>
                        @if (! empty($template['subtitle']))
                            <div class="ledger-report__subtitle">{{ $template['subtitle'] }}</div>
                        @endif
                        @if (! empty($template['header_text']))
                            <div class="ledger-report__copy">{{ $template['header_text'] }}</div>
                        @endif
                    </td>
                    <td class="ledger-report__brand">
                        @if ($logoImageSrc)
                            <div class="ledger-report__logo">
                                <img src="{{ $logoImageSrc }}" alt="{{ $template['title'] }}">
                            </div>
                        @endif
                    </td>
                </tr>
            </table>

            <table class="ledger-report__meta-table">
                @foreach (array_chunk($metaCards, 4) as $metaRow)
                    <tr>
                        @foreach ($metaRow as $card)
                            <td class="ledger-report__meta-card">
                                <span class="ledger-report__meta-label">{{ $card['label'] }}</span>
                                <span class="ledger-report__meta-value">{{ $card['value'] ?: '-' }}</span>
                            </td>
                        @endforeach
                        @for ($i = count($metaRow); $i < 4; $i++)
                            <td></td>
                        @endfor
                    </tr>
                @endforeach
            </table>

            <table class="ledger-report__summary-table">
                <tr>
                    @foreach ($summaryCards as $card)
                        <td class="ledger-report__summary-card">
                            <span class="ledger-report__meta-label">{{ $card['label'] }}</span>
                            <span class="ledger-report__meta-value">{{ $card['value'] ?: '-' }}</span>
                        </td>
                    @endforeach
                    @for ($i = count($summaryCards); $i < 4; $i++)
                        <td></td>
                    @endfor
                </tr>
            </table>

            @if (! empty($template['custom_text']))
                <div class="ledger-report__custom-text">{{ $template['custom_text'] }}</div>
            @endif

            <table class="ledger-report__table">
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
                            <td colspan="{{ count($report['columns']) }}" class="ledger-report__empty">{{ __('finance.empty.no_transactions') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            @if (! empty($template['footer_text']) || ($template['show_page_numbers'] ?? false))
                <div class="ledger-report__footer">
                    <table class="ledger-report__footer-table">
                        <tr>
                            <td class="ledger-report__footer-copy">{{ $template['footer_text'] ?? '' }}</td>
                            @if ($template['show_page_numbers'] ?? false)
                                <td class="ledger-report__page-number">{{ $service->bilingual('Page', 'الصفحة', $language) }} {{ $report['page_number'] ?? 1 }}</td>
                            @endif
                        </tr>
                    </table>
                </div>
            @endif
        </div>
    </div>
</body>
</html>
