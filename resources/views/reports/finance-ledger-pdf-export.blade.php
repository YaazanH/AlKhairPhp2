@php
    $template = $report['template'];
    $logoImage = $template['logo_image_pdf_src'] ?? null;
    $reportNumber = 'FINR-'.str_pad((string) ($generatedReport?->id ?? 0), 6, '0', STR_PAD_LEFT);
    $rows = collect($report['rows'] ?? []);
    $dataPages = $rows->isEmpty() ? collect([collect()]) : $rows->chunk(18)->values();
    $qrPayload = json_encode([
        'report_no' => $reportNumber,
        'fund' => data_get($report, 'cash_box.name'),
        'currency' => data_get($report, 'currency.code'),
        'from' => $report['start'] ?? null,
        'to' => $report['end'] ?? null,
        'opening_balance' => $report['opening_balance'] ?? null,
        'ending_balance' => $report['closing_balance'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $exportedAt = ! empty($report['exported_at']) ? \Illuminate\Support\Carbon::parse($report['exported_at'])->format('d-m-Y H:i') : '-';
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>تقرير مالي</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 12mm 12mm 20mm;
        }
        body { color: #14261a; direction: rtl; font-family: dejavusanscondensed; font-size: 10px; margin: 0; }
        .page { min-height: 253mm; }
        .page-break { page-break-after: always; }
        .first-header { background: #dcefdc; border-bottom: 1px solid #a9c9ae; margin: -12mm -12mm 5mm; padding: 8mm 12mm 5mm; }
        .first-header-table, .meta-table, .ledger-table, .summary-table, .footer-table { border-collapse: collapse; width: 100%; }
        .first-header-table td { border: 0; vertical-align: middle; }
        .logo-cell { text-align: right; width: 25%; }
        .logo-cell img { max-height: 22mm; max-width: 42mm; }
        .title-cell { text-align: center; width: 50%; }
        .title-cell h1 { color: #123d22; font-size: 25px; margin: 0; }
        .security-notice { color: #c62828; font-size: 12px; font-weight: bold; margin-top: 2mm; }
        .meta-box { background: rgba(255,255,255,.88); border: 1px solid #b8cfbb; margin-bottom: 4mm; padding: 3mm; }
        .meta-table td { border: 0; padding: 1.2mm 2mm; vertical-align: middle; }
        .meta-label { color: #3f6849; font-weight: bold; width: 17%; }
        .meta-value { color: #102c18; font-weight: bold; width: 24%; }
        .qr-cell { text-align: center; width: 18%; }
        .ledger-table { background: rgba(255,255,255,.91); }
        .ledger-table th { background: #dcefdc; border: 1px solid #9fbea5; color: #214c2c; font-size: 10px; padding: 2.4mm 2mm; text-align: center; }
        .ledger-table td { border: 1px solid #bfd1c1; font-size: 9px; padding: 2.2mm 2mm; vertical-align: top; }
        .ledger-table .date { text-align: center; white-space: nowrap; width: 16%; }
        .ledger-table .category { width: 38%; }
        .ledger-table .money { direction: ltr; text-align: center; white-space: nowrap; width: 15%; }
        .category-name { color: #193d23; font-weight: bold; }
        .description { color: #647168; font-size: 8px; margin-top: 1mm; }
        .empty { color: #68756b; padding: 12mm !important; text-align: center; }
        .summary-title { color: #173f23; font-size: 20px; margin: 8mm 0 5mm; text-align: center; }
        .summary-table { background: rgba(255,255,255,.92); }
        .summary-table td { border: 1px solid #aac3ae; font-size: 11px; padding: 4mm; vertical-align: top; width: 50%; }
        .summary-label { color: #41694a; display: block; font-size: 9px; font-weight: bold; margin-bottom: 1.5mm; }
        .signature-space { height: 38mm; }
        .signature-line { border-bottom: 1px solid #263e2d; display: inline-block; margin-top: 25mm; width: 70mm; }
        .footer-table { background: #dcefdc; border-top: 1px solid #a9c9ae; color: #173f23; }
        .footer-table td { border: 0; height: 12mm; padding: 1.5mm 5mm; vertical-align: middle; width: 33.33%; }
        .footer-barcode { direction: ltr; text-align: right; }
        .footer-page { font-size: 10px; font-weight: bold; text-align: center; }
    </style>
</head>
<body>
    <htmlpagefooter name="ledgerFooter">
        <table class="footer-table" dir="ltr"><tr><td></td><td class="footer-page" dir="rtl">صفحة {PAGENO} من {nbpg}</td><td class="footer-barcode"><barcode code="{{ $reportNumber }}" type="C39" size="0.75" height="0.8" /></td></tr></table>
    </htmlpagefooter>
    <sethtmlpagefooter name="ledgerFooter" value="on" />

    @foreach ($dataPages as $pageIndex => $pageRows)
        <section class="page page-break">
            @if ($pageIndex === 0)
                <header class="first-header">
                    <table class="first-header-table" dir="ltr"><tr><td style="width:25%"></td><td class="title-cell" dir="rtl"><h1>تقرير مالي</h1><div class="security-notice">سري وهام - غير معد للمداولة</div></td><td class="logo-cell">@if ($logoImage)<img src="{{ $logoImage }}" alt="">@endif</td></tr></table>
                </header>
            @endif

            <div class="meta-box">
                @include('reports.partials.finance-ledger-meta', ['qrPayload' => $qrPayload, 'report' => $report])
            </div>

            <table class="ledger-table">
                <thead><tr><th>التاريخ</th><th>التصنيف والوصف</th><th>مدين</th><th>دائن</th><th>الرصيد</th></tr></thead>
                <tbody>
                    @forelse ($pageRows as $row)
                        <tr><td class="date">{{ $row['transaction_date'] }}</td><td class="category"><div class="category-name">{{ $row['category'] ?: '-' }}</div><div class="description">{{ $row['description'] ?: '-' }}</div></td><td class="money">{{ $row['expense'] ?: '-' }}</td><td class="money">{{ $row['income'] ?: '-' }}</td><td class="money">{{ $row['running_balance'] }}</td></tr>
                    @empty
                        <tr><td colspan="5" class="empty">{{ __('finance.empty.no_transactions') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    @endforeach

    <section class="page">
        <div class="meta-box">
            @include('reports.partials.finance-ledger-meta', ['qrPayload' => $qrPayload, 'report' => $report])
        </div>
        <h2 class="summary-title">ملخص التقرير المالي</h2>
        <table class="summary-table">
            <tr><td><span class="summary-label">المسؤول المالي</span>{{ $report['issuer_name'] ?: '-' }}</td><td><span class="summary-label">إجمالي المصروفات</span><span dir="ltr">{{ data_get($report, 'formatted.expense') }}</span></td></tr>
            <tr><td><span class="summary-label">الرصيد الختامي</span><span dir="ltr">{{ data_get($report, 'formatted.closing_balance') }}</span></td><td><span class="summary-label">إجمالي الإيرادات</span><span dir="ltr">{{ data_get($report, 'formatted.income') }}</span></td></tr>
            <tr><td><span class="summary-label">تاريخ التصدير</span>{{ $exportedAt }}</td><td><span class="summary-label">ملاحظات</span>{!! nl2br(e($template['custom_text'] ?: '-')) !!}</td></tr>
            <tr><td colspan="2" class="signature-space"><span class="summary-label">التوقيع</span><span class="signature-line"></span></td></tr>
        </table>
    </section>
</body>
</html>
