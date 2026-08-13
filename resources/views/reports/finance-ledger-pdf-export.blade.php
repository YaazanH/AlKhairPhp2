@php
    $template = $report['template'];
    $logoImage = $template['logo_image_pdf_src'] ?? null;
    $signatureImage = $report['issuer_signature_pdf_src'] ?? null;
    $stampImage = $report['report_stamp_pdf_src'] ?? null;
    $reportNumber = $service->reportNumber($generatedReport, $report);
    $exportedAt = ! empty($report['exported_at']) ? \Illuminate\Support\Carbon::parse($report['exported_at'])->format('d-m-Y') : '-';
    $qrSvg = (new \Mpdf\QrCode\Output\Svg())->output(new \Mpdf\QrCode\QrCode(json_encode(['report' => $reportNumber, 'fund' => data_get($report, 'cash_box.name'), 'from' => $report['start'] ?? null, 'to' => $report['end'] ?? null], JSON_UNESCAPED_UNICODE)), 80, 'transparent', 'black');
    $qrImage = 'data:image/svg+xml;base64,'.base64_encode($qrSvg);
@endphp
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>تقرير مالي</title>
    <style>
        @page { margin: 34mm 12mm 18mm; header: ledgerHeader; footer: ledgerFooter; }
        @page :first { header: ledgerFirstHeader; }
        body { color: #18351f; direction: rtl; font-family: dubai, sans-serif; font-size: 9pt; margin: 0; }
        .header-wrap { margin: 0 -12mm; }
        .header-bar { background: #dcefdc; border-bottom: 1px solid #9fc2a5; padding: 3mm 12mm 2.5mm; }
        .header-table, .meta-table, .ledger, .summary, .footer-table { border-collapse: collapse; width: 100%; }
        .header-table td { border: 0; padding: 0; vertical-align: middle; }
        .logo { width: 22%; }
        .logo img { height: auto; max-height: 23mm; max-width: 42mm; width: auto; }
        .title { color: #164d27; font-size: 18pt; font-weight: bold; text-align: center; width: 56%; }
        .notice { color: #a52323; font-size: 8pt; font-weight: bold; margin-top: 2mm; }
        .report-no { color: #355f3e; direction: ltr; text-align: left; width: 22%; }
        .continuation { color: #78907e; direction: rtl; font-size: 7pt; font-weight: normal; margin-top: .8mm; }
        .meta-wrap { background: transparent; border-bottom: 1px solid #bad1be; margin-bottom: 2mm; padding: 2mm 0; }
        .meta-table td { border: 0; padding: .7mm 1.2mm; text-align: right; vertical-align: middle; }
        .meta-label { color: #58715e; font-size: 7.8pt; font-weight: bold; white-space: nowrap; width: 13%; }
        .meta-value { color: #173b20; font-weight: bold; padding-right: 2.5mm !important; width: 20%; }
        .meta-qr { text-align: left !important; width: 10mm; }
        .meta-qr img { height: 9mm; width: 9mm; }
        .footer { background: #dcefdc; border-top: 1px solid #9fc2a5; margin: 0 -12mm; padding: 1.5mm 12mm; }
        .footer-table td { background: #dcefdc; border: 0; height: 8mm; padding: 0 2mm; vertical-align: middle; width: 33.33%; }
        .footer-page { font-weight: bold; text-align: center; }
        .footer-code { background: transparent !important; direction: ltr; font-family: code39; font-size: 20pt; line-height: 1; text-align: right; }
        .statement-gap { height: 2mm; }
        .ledger { page-break-inside: auto; }
        .ledger thead { display: table-header-group; }
        .ledger tr { page-break-inside: avoid; }
        .ledger th { background: #dcefdc; border: 1px solid #9fbea5; color: #214c2c; font-size: 8.5pt; padding: 2mm 1.5mm; text-align: center; }
        .ledger td { border: 1px solid #bfd1c1; font-size: 8.2pt; padding: 1.8mm 1.5mm; vertical-align: top; }
        .ledger tbody tr:nth-child(even) td { background: rgba(220, 239, 220, .60); }
        .date { text-align: center; white-space: nowrap; width: 13%; }
        .category { width: 39%; }
        .money { direction: ltr; text-align: right; white-space: nowrap; width: 16%; }
        .category-name { font-weight: bold; }
        .description { color: #637267; font-size: 7.5pt; margin-top: .4mm; }
        .empty { color: #68756b; padding: 10mm !important; text-align: center; }
        .summary { border-collapse: collapse; margin-top: 4mm; page-break-inside: avoid; table-layout: fixed; width: 100%; }
        .summary-title { color: #164d27; font-size: 12pt; font-weight: bold; margin: 4mm 0 1.5mm; text-align: center; }
        .summary td { border: 0; padding: 2.1mm 1.2mm; text-align: right; vertical-align: middle; }
        .summary-label { color: #58715e; font-size: 8pt; font-weight: bold; width: 18%; }
        .summary-value { direction: ltr; font-weight: bold; text-align: right; width: 32%; }
        .summary-notes { text-align: right; }
        .signature { border:0 !important; height: 29mm; padding-top:4mm !important; }
        .signature-layout { border-collapse:collapse; table-layout:fixed; width:100%; }
        .signature-layout,.signature-layout td { border:0; padding:0; }
        .stamp-block { text-align:left; vertical-align:bottom; width:50%; }
        .stamp-block img { height:auto; max-height:25mm; max-width:34mm; width:auto; }
        .signature-block { text-align:center; vertical-align:bottom; width:50%; }
        .signature-mark { height:16mm; position:relative; width:100%; }
        .signature-image { display:block; margin:0 auto; max-height:16mm; max-width:70%; width:auto; }
        .signature-line { border-top:1px solid #315b3b; bottom:2.4mm; left:0; position:absolute; width:100%; }
        .signature-name { color: #637267; display: block; font-size: 7.5pt; margin-top: 2mm; text-align:center; }
    </style>
</head>
<body>
<htmlpageheader name="ledgerFirstHeader">
    <div class="header-wrap">
        <div class="header-bar"><table class="header-table" dir="ltr"><tr><td class="report-no">{{ $reportNumber }}</td><td class="title" dir="rtl">تقرير مالي<div class="notice">سري وهام - غير معد للمداولة</div></td><td class="logo" dir="rtl">@if ($logoImage)<img src="{{ $logoImage }}" alt="">@endif</td></tr></table></div>
    </div>
</htmlpageheader>
<htmlpageheader name="ledgerHeader">
    <div class="header-wrap">
        <div class="header-bar"><table class="header-table" dir="ltr"><tr><td class="report-no">{{ $reportNumber }}<div class="continuation">متابعة</div></td><td class="title" dir="rtl">تقرير مالي<div class="notice">سري وهام - غير معد للمداولة</div></td><td class="logo" dir="rtl">@if ($logoImage)<img src="{{ $logoImage }}" alt="">@endif</td></tr></table></div>
    </div>
</htmlpageheader>
<htmlpagefooter name="ledgerFooter">
    <div class="footer"><table class="footer-table" dir="rtl"><tr><td class="footer-code">*{{ $reportNumber }}*</td><td class="footer-page">صفحة {PAGENO} من {nbpg}</td><td></td></tr></table></div>
</htmlpagefooter>

<div class="statement-gap"></div>
<div class="meta-wrap"><table class="meta-table">
    <tr><td class="meta-label">الدورة</td><td class="meta-value">{{ $report['default_course'] ?? '-' }}</td><td class="meta-label">الصندوق</td><td class="meta-value">{{ data_get($report, 'cash_box.name') }}</td><td class="meta-label">العملة</td><td class="meta-value">{{ data_get($report, 'currency.code') }} - {{ data_get($report, 'currency.name') }}</td><td class="meta-qr" rowspan="2"><img src="{{ $qrImage }}" alt=""></td></tr>
    <tr><td class="meta-label">تاريخ البداية</td><td class="meta-value">{{ \Illuminate\Support\Carbon::parse($report['start'])->format('d-m-Y') }}</td><td class="meta-label">تاريخ النهاية</td><td class="meta-value">{{ \Illuminate\Support\Carbon::parse($report['end'])->format('d-m-Y') }}</td><td class="meta-label">الرصيد الافتتاحي</td><td class="meta-value" dir="ltr">{{ data_get($report, 'formatted.opening_balance') }}</td></tr>
</table></div>
<table class="ledger">
    <thead><tr><th>التاريخ</th><th>التصنيف والوصف</th><th>مصاريف</th><th>دخل</th><th>الرصيد</th></tr></thead>
    <tbody>
    @forelse (($report['rows'] ?? []) as $row)
        <tr><td class="date">{{ $row['transaction_date'] }}</td><td class="category"><div class="category-name">{{ $row['category'] ?: '-' }}</div>@if($row['description'])<div class="description">{{ $row['description'] }}</div>@endif</td><td class="money">{{ $row['expense'] ?: '-' }}</td><td class="money">{{ $row['income'] ?: '-' }}</td><td class="money">{{ $row['running_balance'] }}</td></tr>
    @empty
        <tr><td colspan="5" class="empty">{{ __('finance.empty.no_transactions') }}</td></tr>
    @endforelse
    </tbody>
</table>

<div class="summary-title">ملخص التقرير المالي</div>
<table class="summary">
    <tr><td class="summary-label">إجمالي المصاريف</td><td class="summary-value">{{ data_get($report, 'formatted.expense') }}</td><td class="summary-label">إجمالي الإيرادات</td><td class="summary-value">{{ data_get($report, 'formatted.income') }}</td></tr>
    <tr><td class="summary-label">الرصيد الختامي</td><td class="summary-value">{{ data_get($report, 'formatted.closing_balance') }}</td><td class="summary-label">تاريخ التصدير</td><td class="summary-value">{{ $exportedAt }}</td></tr>
    <tr><td class="summary-label">ملاحظات</td><td colspan="3" class="summary-notes">{{ ($report['notes'] ?? null) ?: '-' }}</td></tr>
    <tr><td colspan="4" class="signature"><table class="signature-layout" dir="ltr"><tr><td class="stamp-block">@if($stampImage)<img src="{{ $stampImage }}" alt="">@endif</td><td class="signature-block"><div class="signature-mark">@if($signatureImage)<img class="signature-image" src="{{ $signatureImage }}" alt="">@endif<div class="signature-line"></div></div><span class="signature-name">{{ $report['issuer_name'] ?: '-' }}</span></td></tr></table></td></tr>
</table>
</body>
</html>
