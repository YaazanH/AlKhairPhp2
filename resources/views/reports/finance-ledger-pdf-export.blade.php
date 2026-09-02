@php
    $template = $report['template'];
    $logoImage = $template['logo_image_pdf_src'] ?? null;
    $signatureImage = $report['issuer_signature_pdf_src'] ?? null;
    $stampImage = $report['report_stamp_pdf_src'] ?? null;
    $reportNumber = $service->reportNumber($generatedReport, $report);
    $exportedAt = ! empty($report['exported_at']) ? \Illuminate\Support\Carbon::parse($report['exported_at'])->format('d-m-Y') : '-';
    $qrCode = new \Mpdf\QrCode\QrCode(json_encode(['report' => $reportNumber, 'fund' => data_get($report, 'cash_box.name'), 'from' => $report['start'] ?? null, 'to' => $report['end'] ?? null], JSON_UNESCAPED_UNICODE));
    $qrCode->disableBorder();
    $qrSvg = (new \Mpdf\QrCode\Output\Svg())->output($qrCode, 56, 'transparent', 'black');
    $qrImage = 'data:image/svg+xml;base64,'.base64_encode($qrSvg);
    $kashidaLabels = [
        'start_date' => $service->balanceArabicPdfKashidas('تاريخ البداية', 14),
        'fund' => $service->balanceArabicPdfKashidas('الصندوق', 14),
        'currency' => $service->balanceArabicPdfKashidas('العملة', 49),
        'total_expense' => $service->balanceArabicPdfKashidas('إجمالي', 14)."\u{00A0}".'المصاريف',
        'closing_balance' => $service->balanceArabicPdfKashidas('الرصيد', 16)."\u{00A0}".$service->balanceArabicPdfKashidas('الختامي', 9),
        'total_income' => $service->balanceArabicPdfKashidas('إجمالي', 4)."\u{00A0}".$service->balanceArabicPdfKashidas('الإيرادات', 4),
        'export_date' => $service->balanceArabicPdfKashidas('تاريخ', 14)."\u{00A0}".$service->balanceArabicPdfKashidas('التصدير', 8),
    ];
@endphp
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>تقرير مالي</title>
    <style>
        @page { margin: 0 12mm 18mm; margin-header: 0; header: ledgerHeader; footer: ledgerFooter; }
        @page :first { header: ledgerFirstHeader; }
        body { color: #18351f; direction: rtl; font-family: dubai, sans-serif; font-size: 9pt; margin: 0; }
        .header-wrap { margin: 0 -12mm; }
        .header-bar { background: #dcefdc; border-bottom: 1px solid #9fc2a5; padding: 3mm 12mm 2.5mm; }
        .header-table, .meta-table, .ledger, .summary, .footer-table { border-collapse: collapse; width: 100%; }
        .header-table td { border: 0; padding: 0; vertical-align: middle; }
        .logo { vertical-align: middle !important; width: 22%; }
        .logo img { display: block; height: 18mm; margin-left: auto; max-width: 42mm; width: auto; }
        .title { color: #164d27; font-size: 20pt; font-weight: bold; text-align: center; vertical-align: middle !important; width: 56%; }
        .report-no { color: #355f3e; direction: ltr; text-align: left; width: 22%; }
        .continuation { color: #78907e; direction: rtl; font-size: 7pt; font-weight: normal; margin-top: .8mm; }
        .meta-wrap { background: transparent; margin: 0 0 1.65mm -1mm; padding: 0; }
        .meta-table { table-layout: fixed; }
        .meta-table td { border: 0; padding: .7mm 1.2mm; text-align: right; vertical-align: middle; }
        .meta-label { color: #58715e; font-size: 7.8pt; font-weight: bold; white-space: nowrap; }
        .finance-report-kashida-label { direction: rtl; display: inline-block; line-height: inherit; unicode-bidi: isolate; vertical-align: baseline; white-space: nowrap; }
        .meta-value { color: #173b20; font-size: 8.4pt; font-weight: bold; overflow: hidden; padding-right: 2.5mm !important; text-overflow: ellipsis; white-space: nowrap; }
        .meta-qr { direction: ltr !important; padding-left: 0 !important; text-align: left !important; width: 15mm; }
        .meta-qr img { display: block; height: 6.3mm; margin: 0; width: 6.3mm; }
        .footer { background: #dcefdc; border-top: 1px solid #9fc2a5; margin: 0 -12mm; padding: 1.5mm 12mm; }
        .footer-table td { background: #dcefdc; border: 0; height: 8mm; padding: 0; vertical-align: middle; width: 33.33%; }
        .footer-page { font-weight: bold; text-align: center; }
        .footer-code { background: transparent !important; direction: ltr; font-family: code39; font-size: 20pt; line-height: 1; padding-right: 0 !important; text-align: right; }
        .footer-notice { color: #a52323; direction: rtl; font-size: 8pt; font-weight: bold; padding-left: 0 !important; text-align: left; white-space: nowrap; }
        .ledger { page-break-inside: auto; }
        .ledger thead { display: table-header-group; }
        .ledger tr { page-break-inside: avoid; }
        .ledger-page-gap th { background: transparent; border: 0; height: 2.1mm; line-height: 0; padding: 0; }
        .ledger th { background: #dcefdc; border: 1px solid #9fbea5; border-bottom: 3px double #9fbea5; color: #214c2c; font-size: 8.5pt; padding: 2mm 1.5mm; text-align: center; }
        .ledger td { border: 1px solid #bfd1c1; font-size: 8.2pt; padding: 1.8mm 1.5mm; vertical-align: top; }
        .ledger tbody tr:nth-child(even) td { background: rgba(220, 239, 220, .4); }
        .date { text-align: center; white-space: nowrap; width: 13%; }
        .category { width: 39%; }
        .money { direction: ltr; text-align: right; white-space: nowrap; width: 16%; }
        .ledger td.date, .ledger td.money { vertical-align: middle; }
        .debit-value { color: #b42318; }
        .credit-value { color: #16713d; }
        .category-name { font-weight: bold; }
        .description { color: #637267; font-size: 7.5pt; margin-top: .4mm; }
        .empty { color: #68756b; padding: 10mm !important; text-align: center; }
        .summary { border-collapse: collapse; margin-top: 4mm; page-break-inside: avoid; table-layout: fixed; width: 100%; }
        .summary td { border: 0; padding: 2.1mm 1.2mm; text-align: right; vertical-align: middle; }
        .summary-label { color: #58715e; font-size: 8pt; font-weight: bold; white-space: nowrap; width: 14%; }
        .summary-label--right-pair { width: 15.6%; }
        .summary-label .finance-report-kashida-label { display: block; height: 4mm; line-height: 4mm; vertical-align: middle; }
        .summary-baseline-spacer { display: block; font-size: 1pt; height: 3.6mm; line-height: 3.6mm; }
        .summary-value { direction: ltr; font-weight: bold; text-align: right; width: 16%; }
        .summary-value--right-pair { width: 14.4%; }
        .summary-label-wide { width: 10%; }
        .summary-value-wide { direction: rtl; width: 30%; }
        .summary-notes { text-align: right; }
        .signature { border:0 !important; height: 44mm; padding-top:4mm !important; }
        .signature-layout { border-collapse:collapse; table-layout:fixed; width:100%; }
        .signature-layout,.signature-layout td { border:0; padding:0; }
        .stamp-block { text-align:left; vertical-align:bottom; width:50%; }
        .stamp-block img { display:block; height:auto; margin:0; max-height:40mm; max-width:40mm; width:auto; }
        .signature-block { text-align:center; vertical-align:bottom; width:50%; }
        .signature-mark { height:31mm; width:100%; }
        .signature-image-space { height:27mm; text-align:center; width:100%; }
        .signature-image { display:inline-block; height:auto; margin:0 auto; max-height:26mm; max-width:70%; width:auto; }
    </style>
</head>
<body>
<htmlpageheader name="ledgerFirstHeader">
    <div class="header-wrap">
        <div class="header-bar"><table class="header-table" dir="ltr"><tr><td class="report-no">{{ $reportNumber }}</td><td class="title" dir="rtl">تقرير مالي</td><td class="logo" dir="rtl">@if ($logoImage)<img src="{{ $logoImage }}" alt="" height="18mm" max-height="18mm" max-width="42mm" style="display:block;height:18mm;max-height:18mm;max-width:42mm;width:auto">@endif</td></tr></table></div>
    </div>
</htmlpageheader>
<htmlpageheader name="ledgerHeader">
    <div class="header-wrap">
        <div class="header-bar"><table class="header-table" dir="ltr"><tr><td class="report-no">{{ $reportNumber }}<div class="continuation">متابعة</div></td><td class="title" dir="rtl">تقرير مالي</td><td class="logo" dir="rtl">@if ($logoImage)<img src="{{ $logoImage }}" alt="" height="18mm" max-height="18mm" max-width="42mm" style="display:block;height:18mm;max-height:18mm;max-width:42mm;width:auto">@endif</td></tr></table></div>
    </div>
</htmlpageheader>
<htmlpagefooter name="ledgerFooter">
    <div class="footer"><table class="footer-table" dir="rtl"><tr><td class="footer-code"><span>*{{ $reportNumber }}*</span></td><td class="footer-page">صفحة {PAGENO} من {nbpg}</td><td class="footer-notice"><span>سري وهام - غير معد للمداولة</span></td></tr></table></div>
</htmlpagefooter>

<div class="meta-wrap"><table class="meta-table">
    <colgroup><col style="width:9%"><col style="width:22%"><col style="width:9%"><col style="width:20%"><col style="width:9%"><col style="width:23%"><col style="width:8%"></colgroup>
    <tr><td class="meta-label"><span class="finance-report-kashida-label" aria-label="العام الأكاديمي">العام الأكاديمي</span></td><td class="meta-value">{{ $report['academic_year'] ?? '-' }}</td><td class="meta-label"><span class="finance-report-kashida-label" data-finance-report-kashida-label="fund" aria-label="الصندوق">{{ $kashidaLabels['fund'] }}</span></td><td class="meta-value">{{ data_get($report, 'cash_box.name') }}</td><td class="meta-label"><span class="finance-report-kashida-label" data-finance-report-kashida-label="currency" aria-label="العملة">{{ $kashidaLabels['currency'] }}</span></td><td class="meta-value">{{ data_get($report, 'currency.code') }} - {{ data_get($report, 'currency.name') }}</td><td class="meta-qr" rowspan="2" dir="ltr"><img src="{{ $qrImage }}" alt=""></td></tr>
    <tr><td class="meta-label"><span class="finance-report-kashida-label" data-finance-report-kashida-label="start-date" aria-label="تاريخ البداية">{{ $kashidaLabels['start_date'] }}</span></td><td class="meta-value">{{ \Illuminate\Support\Carbon::parse($report['start'])->format('d-m-Y') }}</td><td class="meta-label"><span class="finance-report-kashida-label" aria-label="تاريخ النهاية">تاريخ النهاية</span></td><td class="meta-value">{{ \Illuminate\Support\Carbon::parse($report['end'])->format('d-m-Y') }}</td><td class="meta-label"><span class="finance-report-kashida-label" aria-label="الرصيد الافتتاحي">الرصيد الافتتاحي</span></td><td class="meta-value" dir="ltr">{{ data_get($report, 'formatted.opening_balance') }}</td></tr>
</table></div>
<table class="ledger">
    <thead><tr class="ledger-page-gap"><th colspan="5"></th></tr><tr><th>التاريخ</th><th>الوصف</th><th>مدين</th><th>دائن</th><th>الرصيد</th></tr></thead>
    <tbody>
    @forelse (($report['rows'] ?? []) as $row)
        <tr><td class="date">{{ $row['transaction_date'] }}</td><td class="category"><div class="category-name">{{ $row['category'] ?: '-' }}</div>@if($row['description'])<div class="description">{{ $row['description'] }}</div>@endif</td><td class="money">@if($row['expense'])<span class="debit-value">{{ $row['expense'] }}</span>@else - @endif</td><td class="money">@if($row['income'])<span class="credit-value">{{ $row['income'] }}</span>@else - @endif</td><td class="money">{{ $row['running_balance'] }}</td></tr>
    @empty
        <tr><td colspan="5" class="empty">{{ __('finance.empty.no_transactions') }}</td></tr>
    @endforelse
    </tbody>
</table>

<table class="summary">
    <tr><td class="summary-label summary-label--right-pair"><div class="summary-baseline-spacer">&nbsp;</div><div class="finance-report-kashida-label" data-finance-report-kashida-label="total-expense" aria-label="إجمالي المصاريف">{{ $kashidaLabels['total_expense'] }}</div></td><td class="summary-value summary-value--right-pair">{{ data_get($report, 'formatted.expense') }}</td><td class="summary-label"><div class="finance-report-kashida-label" data-finance-report-kashida-label="total-income" aria-label="إجمالي الإيرادات">{{ $kashidaLabels['total_income'] }}</div></td><td class="summary-value">{{ data_get($report, 'formatted.income') }}</td><td class="summary-label summary-label-wide">{{ __('finance.common.notes') }}</td><td class="summary-value summary-value-wide">{{ $report['notes'] ?? '' }}</td></tr>
    <tr><td class="summary-label summary-label--right-pair"><div class="finance-report-kashida-label" data-finance-report-kashida-label="closing-balance" aria-label="الرصيد الختامي">{{ $kashidaLabels['closing_balance'] }}</div></td><td class="summary-value summary-value--right-pair">{{ data_get($report, 'formatted.closing_balance') }}</td><td class="summary-label"><div class="finance-report-kashida-label" data-finance-report-kashida-label="export-date" aria-label="تاريخ التصدير">{{ $kashidaLabels['export_date'] }}</div><div class="summary-baseline-spacer">&nbsp;</div></td><td class="summary-value">{{ $exportedAt }}</td><td class="summary-label summary-label-wide"></td><td class="summary-value summary-value-wide"></td></tr>
    <tr><td colspan="6" class="signature"><table class="signature-layout" dir="ltr"><tr><td class="stamp-block">@if($stampImage)<img src="{{ $stampImage }}" alt="">@endif</td><td class="signature-block"><div class="signature-mark"><div class="signature-image-space">@if($signatureImage)<img class="signature-image" src="{{ $signatureImage }}" alt="">@endif</div></div></td></tr></table></td></tr>
</table>
</body>
</html>
