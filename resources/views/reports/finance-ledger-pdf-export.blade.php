@php
    /** @var \App\Services\FinanceReportService $service */
    $template = $report['template'];
    $language = $template['language'] ?? \App\Models\FinanceReportTemplate::LANGUAGE_BOTH;
    $dir = $language === \App\Models\FinanceReportTemplate::LANGUAGE_EN ? 'ltr' : 'rtl';
    $lang = $language === \App\Models\FinanceReportTemplate::LANGUAGE_EN ? 'en' : 'ar';
    $textAlign = $dir === 'rtl' ? 'right' : 'left';
    $brandAlign = $dir === 'rtl' ? 'left' : 'right';
    $pdfFontFamily = $dir === 'rtl' ? 'dejavusanscondensed' : 'sans';
    $shapeType = $template['shape_type'] ?? null;
    $shapeColor = $template['shape_color'] ?? '#0f7a3d';
    $shapeOpacity = (float) ($template['shape_opacity'] ?? 0.12);
    $backgroundImageSrc = $template['background_image_pdf_src'] ?? null;
    $logoImageSrc = $template['logo_image_pdf_src'] ?? null;
    $columnCount = max(1, count($report['columns'] ?? []));

    $details = [
        [
            'label' => $service->bilingual('Fund', 'الصندوق', $language),
            'value' => data_get($report, 'cash_box.name'),
        ],
        [
            'label' => $service->bilingual('Currency', 'العملة', $language),
            'value' => trim((string) data_get($report, 'currency.code').' - '.(string) data_get($report, 'currency.name')),
        ],
        [
            'label' => $service->bilingual('From date', 'من تاريخ', $language),
            'value' => ! empty($report['start']) ? \Illuminate\Support\Carbon::parse($report['start'])->format('d-m-Y') : '',
        ],
        [
            'label' => $service->bilingual('To date', 'إلى تاريخ', $language),
            'value' => ! empty($report['end']) ? \Illuminate\Support\Carbon::parse($report['end'])->format('d-m-Y') : '',
        ],
        [
            'label' => $service->bilingual('Report date', 'تاريخ التقرير', $language),
            'value' => ! empty($report['report_date']) ? \Illuminate\Support\Carbon::parse($report['report_date'])->format('d-m-Y') : '',
        ],
    ];

    if (($template['show_issuer_name'] ?? false) && ! empty($report['issuer_name'])) {
        $details[] = [
            'label' => $service->bilingual('Issued by', 'أصدر بواسطة', $language),
            'value' => $report['issuer_name'],
        ];
    }

    if (($template['include_exported_at'] ?? false) && ! empty($report['exported_at'])) {
        $details[] = [
            'label' => $service->bilingual('Exported at', 'تاريخ التصدير', $language),
            'value' => \Illuminate\Support\Carbon::parse($report['exported_at'])->format('d-m-Y H:i'),
        ];
    }

    $summary = [];

    if ($template['include_opening_balance'] ?? false) {
        $summary[] = [
            'label' => $service->bilingual('Opening balance', 'الرصيد الافتتاحي', $language),
            'value' => data_get($report, 'formatted.opening_balance'),
        ];
    }

    $summary[] = [
        'label' => $service->bilingual('Income', 'الإيرادات', $language),
        'value' => data_get($report, 'formatted.income'),
    ];
    $summary[] = [
        'label' => $service->bilingual('Expense', 'المصاريف', $language),
        'value' => data_get($report, 'formatted.expense'),
    ];

    if ($template['include_closing_balance'] ?? false) {
        $summary[] = [
            'label' => $service->bilingual('Closing balance', 'الرصيد الختامي', $language),
            'value' => data_get($report, 'formatted.closing_balance'),
        ];
    }

    $shapeStyle = match ($shapeType) {
        'rectangle' => 'background: '.$shapeColor.'; display: inline-block; height: 10px; opacity: '.$shapeOpacity.'; width: 160px;',
        'circle' => 'background: '.$shapeColor.'; border-radius: 999px; display: inline-block; height: 24px; opacity: '.$shapeOpacity.'; width: 24px;',
        'triangle' => 'border-color: transparent transparent '.$shapeColor.' transparent; border-style: solid; border-width: 0 14px 24px 14px; display: inline-block; height: 0; opacity: '.$shapeOpacity.'; width: 0;',
        default => null,
    };
@endphp
<!DOCTYPE html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <title>{{ $template['title'] }}</title>
</head>
<body style="color:#102316; direction:{{ $dir }}; font-family:{{ $pdfFontFamily }}; font-size:12px; line-height:1.45; margin:0; text-align:{{ $textAlign }};">
    @if ($shapeStyle)
        <p style="margin:0 0 10px; text-align:{{ $brandAlign }};">
            <span style="{{ $shapeStyle }}"></span>
        </p>
    @endif

    @if ($backgroundImageSrc)
        <p style="margin:0 0 12px;">
            <img src="{{ $backgroundImageSrc }}" alt="" style="max-height:80px; width:100%;">
        </p>
    @endif

    @if ($logoImageSrc)
        <p style="margin:0 0 8px; text-align:{{ $brandAlign }};">
            <img src="{{ $logoImageSrc }}" alt="{{ $template['title'] }}" style="max-height:64px; max-width:140px;">
        </p>
    @endif

    <p style="color:#637365; font-size:10px; font-weight:700; margin:0 0 4px;">{{ $template['name'] }}</p>
    <h1 style="font-size:24px; font-weight:700; margin:0 0 6px;">{{ $template['title'] }}</h1>

    @if (! empty($template['subtitle']))
        <p style="color:#516255; margin:0 0 6px; white-space:pre-line;">{{ $template['subtitle'] }}</p>
    @endif

    @if (! empty($template['header_text']))
        <p style="color:#516255; margin:0 0 6px; white-space:pre-line;">{{ $template['header_text'] }}</p>
    @endif

    <div style="border-top:1px solid #dce6db; margin-top:12px; padding-top:12px;">
        @foreach ($details as $detail)
            <p style="margin:0 0 5px;">
                <span style="color:#31543b; font-weight:700;">{{ $detail['label'] }}:</span>
                <span>{{ $detail['value'] ?: '-' }}</span>
            </p>
        @endforeach
    </div>

    <div style="border-top:1px solid #dce6db; margin-top:12px; padding-top:12px;">
        @foreach ($summary as $item)
            <p style="font-size:13px; font-weight:700; margin:0 0 5px;">
                <span style="color:#31543b;">{{ $item['label'] }}:</span>
                <span>{{ $item['value'] ?: '-' }}</span>
            </p>
        @endforeach
    </div>

    @if (! empty($template['custom_text']))
        <div style="background:#fbfdf9; border:1px dashed #dce6db; margin-top:12px; padding:10px 12px; white-space:pre-line;">{{ $template['custom_text'] }}</div>
    @endif

    <table border="1" width="100%" cellpadding="6" cellspacing="0" style="border-collapse:collapse; margin-top:12px;">
        <thead>
            <tr>
                @foreach ($report['columns'] as $column)
                    <th style="background:#e9f2e8; color:#31543b; font-size:11px; font-weight:700; padding:7px 8px; text-align:{{ $textAlign }}; vertical-align:top;">{{ $service->ledgerColumnLabel($column, $language) }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($report['rows'] as $row)
                <tr>
                    @foreach ($report['columns'] as $column)
                        <td style="font-size:11px; padding:7px 8px; text-align:{{ $textAlign }}; vertical-align:top;">{{ $service->ledgerColumnValue($row, $column) }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $columnCount }}" style="color:#637365; font-size:11px; padding:7px 8px; text-align:center;">{{ __('finance.empty.no_transactions') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if (! empty($template['footer_text']))
        <div style="border-top:1px solid #dce6db; margin-top:12px; padding-top:10px;">
            <p style="color:#516255; margin:0; white-space:pre-line;">{{ $template['footer_text'] }}</p>
        </div>
    @endif

    @if ($template['show_page_numbers'] ?? false)
        <p style="color:#31543b; font-size:11px; font-weight:700; margin:8px 0 0; text-align:{{ $brandAlign }};">{{ $service->bilingual('Page', 'الصفحة', $language) }} {{ $report['page_number'] ?? 1 }}</p>
    @endif
</body>
</html>
