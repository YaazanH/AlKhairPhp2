<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <style>
        @page{margin:0 14mm 18mm;margin-header:10mm;header:pdf-header;footer:pdf-footer}
        @page :first{header:pdf-first-header}
        body{font-family:dubai,sans-serif;color:#17231b;font-size:11px;font-weight:400}
        table{width:100%;border-collapse:collapse}
        th,td{border:1px solid #aebbb1;padding:7px;text-align:{{ app()->isLocale('ar') ? 'right' : 'left' }}}
        th{background:#dfece2;font-family:dubai,sans-serif;font-weight:bold;text-align:center;border-bottom:3px double #aebbb1}
        thead{display:table-header-group}
        tbody tr:nth-child(even) td{background:#f1f7f2}
        .pdf-header,.pdf-footer{background:#cfe7d6;padding:2mm 3mm}
        .pdf-header img{height:18mm;max-width:35mm;width:auto}
        .header-table{border-collapse:collapse;table-layout:fixed;width:100%}
        .header-table td{border:0;padding:0;vertical-align:middle}
        .header-side{width:22%}
        .header-copy{text-align:center;width:56%}
        .header-title{font-family:dubai,sans-serif;font-size:20px;font-weight:bold}
        .header-subtitle{font-size:13px;font-weight:500;margin-top:1mm}
        .header-meta{font-size:9px}
        .header-meta[dir=rtl]{position:relative;left:-2mm}
        .header-meta-table{margin:0 auto}
        .header-meta-row{width:26mm;table-layout:auto;direction:ltr;margin:0 auto}
        .header-meta-row td{border:0;padding:0 0 0.8mm;white-space:nowrap}
        .meta-label{font-family:dubaimedium,sans-serif;font-weight:normal}
        .meta-value{direction:ltr}
        .pdf-footer{color:#526158;font-size:9px;text-align:center}
        .numeric{direction:ltr;text-align:{{ app()->isLocale('ar') ? 'right' : 'left' }};padding-right:{{ app()->isLocale('ar') ? '36px' : '7px' }};white-space:nowrap}
        .numeric.row-number{text-align:center;padding-right:7px}
        .numeric.quantity-value,.numeric.points-value{text-align:center;padding-right:7px}
        .item-name{padding-right:{{ app()->isLocale('ar') ? '14px' : '7px' }}}
        .row-number{width:6mm}
        .department-items-table{table-layout:fixed}
        .department-items-table .row-number{width:7%}
        .department-items-table .equal-column{width:18.6%}
    </style>
</head>
<body>
    @php
        $dateLabel = __('course_end.date_label');
        $pointPriceLabel = __('course_end.point_market.department.point_price').':';
        $dateValue = now()->format('d-m-Y');
        $pointPriceValue = \App\Support\NumberFormatter::withSuffix($department->point_price, $localCurrency->symbol ?: $localCurrency->code, 2);
        $invoiceCurrencyLabel = $department->items->pluck('currency_code')->filter()->unique()->values()->implode(' / ') ?: '—';
        $localCurrencyLabel = $department->items->pluck('local_currency_code')->filter()->unique()->values()->implode(' / ') ?: $localCurrency->code;
        $headerMeta = app()->isLocale('ar')
            ? '<div class="header-meta-table" dir="ltr"><table class="header-meta-row"><tr><td class="meta-value" style="text-align:left">'.$dateValue.'</td><td class="meta-label" dir="rtl" style="text-align:right">'.$dateLabel.'</td></tr></table><table class="header-meta-row"><tr><td class="meta-value" style="text-align:left">'.$pointPriceValue.'</td><td class="meta-label" dir="rtl" style="text-align:right">'.$pointPriceLabel.'</td></tr></table></div>'
            : '<div class="header-meta-table" dir="ltr"><table class="header-meta-row"><tr><td class="meta-label" style="text-align:left">'.$dateLabel.'</td><td class="meta-value" style="text-align:right">'.$dateValue.'</td></tr></table><table class="header-meta-row"><tr><td class="meta-label" style="text-align:left">'.$pointPriceLabel.'</td><td class="meta-value" style="text-align:right">'.$pointPriceValue.'</td></tr></table></div>';
        $departmentTitle = __('course_end.point_market.department.title', ['name' => $department->name]);
    @endphp
    <htmlpageheader name="pdf-first-header">
        <div class="pdf-header"><table class="header-table" dir="ltr"><tr><td class="header-side header-meta" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}">{!! $headerMeta !!}</td><td class="header-copy" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}"><div class="header-title">{{ $departmentTitle }}</div><div class="header-subtitle">{{ $course->name }}</div></td><td class="header-side" dir="rtl">@if($logo)<img src="{{ $logo }}" alt="" height="18mm" max-height="18mm" max-width="35mm" style="height:18mm;max-height:18mm;max-width:35mm;width:auto">@endif</td></tr></table></div>
    </htmlpageheader>
    <htmlpageheader name="pdf-header">
        <div class="pdf-header"><table class="header-table" dir="ltr"><tr><td class="header-side header-meta" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}">{!! $headerMeta !!}</td><td class="header-copy" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}"><div class="header-title">{{ $departmentTitle }}</div><div class="header-subtitle">{{ $course->name }}</div></td><td class="header-side" dir="rtl">@if($logo)<img src="{{ $logo }}" alt="" height="18mm" max-height="18mm" max-width="35mm" style="height:18mm;max-height:18mm;max-width:35mm;width:auto">@endif</td></tr></table></div>
    </htmlpageheader>
    <htmlpagefooter name="pdf-footer"><div class="pdf-footer" dir="ltr">{PAGENO} / {nbpg}</div></htmlpagefooter>
    <table class="department-items-table">
        <colgroup><col class="row-number">@for($column = 0; $column < 5; $column++)<col class="equal-column">@endfor</colgroup>
        <thead><tr><th class="row-number">#</th><th>{{ __('course_end.point_market.department.item') }}</th><th>{{ __('course_end.point_market.department.quantity') }}</th><th>{{ __('course_end.point_market.department.invoice_unit_price') }} (<span dir="ltr">{{ $invoiceCurrencyLabel }}</span>)</th><th>{{ __('course_end.point_market.department.invoice_unit_price') }} (<span dir="ltr">{{ $localCurrencyLabel }}</span>)</th><th>{{ __('course_end.point_market.department.points') }}</th></tr></thead>
        <tbody>
            @forelse($department->items as $item)
                <tr><td class="numeric row-number">{{ $loop->iteration }}</td><td class="item-name">{{ $item->item_name }}</td><td class="numeric quantity-value">{{ \App\Support\NumberFormatter::trimmed($item->quantity, 2) }}</td><td class="numeric">{{ $item->formattedAmount('unit_price') }}</td><td class="numeric">{{ $item->formattedAmount('local_unit_price', true) }}</td><td class="numeric points-value">{{ number_format($item->points($department->point_price)) }}</td></tr>
            @empty
                <tr><td colspan="6" style="text-align:center">{{ __('course_end.point_market.department.empty') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
