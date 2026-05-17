@php
    $template = $report['template'];
    $language = $template['language'] ?? \App\Models\FinanceReportTemplate::LANGUAGE_BOTH;
    $dir = $language === \App\Models\FinanceReportTemplate::LANGUAGE_EN ? 'ltr' : 'rtl';
    $lang = $language === \App\Models\FinanceReportTemplate::LANGUAGE_EN ? 'en' : 'ar';
    $shapeType = $template['shape_type'] ?? null;
    $shapeColor = $template['shape_color'] ?? '#0f7a3d';
    $shapeOpacity = $template['shape_opacity'] ?? 0.12;
    $shapeRgb = $service->shapeRgbChannels($shapeColor);
    $backgroundImageUrl = $template['background_image_url'] ?? null;
    $logoImageUrl = $template['logo_image_url'] ?? null;
    $previewMode = $previewMode ?? false;
@endphp

<style>
    .ledger-report-doc {
        color: #102316;
        font-family: "Segoe UI", Tahoma, Arial, sans-serif;
        line-height: 1.55;
    }
    .ledger-report-doc__page {
        background: #fff;
        border: 1px solid #dce6db;
        border-radius: 24px;
        box-shadow: 0 18px 45px rgba(16, 35, 22, 0.10);
        overflow: hidden;
        position: relative;
    }
    .ledger-report-doc.is-preview .ledger-report-doc__page {
        box-shadow: 0 12px 28px rgba(16, 35, 22, 0.10);
    }
    .ledger-report-doc__background {
        background-image: var(--ledger-report-background);
        background-position: center;
        background-repeat: no-repeat;
        background-size: cover;
        inset: 0;
        opacity: 0.08;
        position: absolute;
        z-index: 0;
    }
    .ledger-report-doc__content {
        padding: 28px;
        position: relative;
        z-index: 1;
    }
    .ledger-report-doc__shape {
        background: rgba(var(--ledger-shape-rgb), var(--ledger-shape-opacity));
        position: absolute;
        z-index: 0;
    }
    .ledger-report-doc__shape--rectangle {
        border-bottom-left-radius: 32px;
        height: 180px;
        inset-inline-end: -50px;
        top: -60px;
        transform: rotate(12deg);
        width: 280px;
    }
    .ledger-report-doc__shape--circle {
        border-radius: 999px;
        height: 220px;
        inset-inline-end: -45px;
        top: -70px;
        width: 220px;
    }
    .ledger-report-doc__shape--triangle {
        background: transparent;
        border-inline-end: 110px solid transparent;
        border-inline-start: 110px solid transparent;
        border-bottom: 190px solid rgba(var(--ledger-shape-rgb), var(--ledger-shape-opacity));
        height: 0;
        inset-inline-end: -30px;
        top: -40px;
        width: 0;
    }
    .ledger-report-doc__header {
        align-items: flex-start;
        border-bottom: 1px solid #dce6db;
        display: flex;
        gap: 18px;
        justify-content: space-between;
        padding-bottom: 22px;
    }
    .ledger-report-doc__eyebrow {
        color: #637365;
        font-size: 12px;
        font-weight: 700;
        letter-spacing: .06em;
        text-transform: uppercase;
    }
    .ledger-report-doc__title {
        font-size: clamp(28px, 4vw, 46px);
        line-height: 1.05;
        margin: 10px 0 0;
    }
    .ledger-report-doc__subtitle,
    .ledger-report-doc__copy,
    .ledger-report-doc__footer-copy {
        color: #516255;
        margin-top: 10px;
        max-width: 760px;
        white-space: pre-line;
    }
    .ledger-report-doc__brand {
        min-width: 200px;
        text-align: end;
    }
    .ledger-report-doc__logo {
        background: rgba(255, 255, 255, 0.92);
        border: 1px solid #dce6db;
        border-radius: 20px;
        display: inline-flex;
        justify-content: center;
        max-width: 180px;
        padding: 10px;
    }
    .ledger-report-doc__logo img {
        display: block;
        height: 72px;
        max-width: 100%;
        object-fit: contain;
    }
    .ledger-report-doc__meta-grid,
    .ledger-report-doc__summary-grid {
        display: grid;
        gap: 12px;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        margin-top: 18px;
    }
    .ledger-report-doc__meta-card,
    .ledger-report-doc__summary-card {
        background: #f4f8f1;
        border: 1px solid #dce6db;
        border-radius: 18px;
        padding: 14px 16px;
    }
    .ledger-report-doc__meta-label {
        color: #637365;
        display: block;
        font-size: 12px;
        font-weight: 700;
        letter-spacing: .06em;
        text-transform: uppercase;
    }
    .ledger-report-doc__meta-value {
        display: block;
        font-size: 16px;
        font-weight: 800;
        margin-top: 5px;
    }
    .ledger-report-doc__summary-card strong {
        display: block;
        font-size: 20px;
        margin-top: 4px;
    }
    .ledger-report-doc__custom-text {
        background: rgba(255, 255, 255, 0.78);
        border: 1px dashed #dce6db;
        border-radius: 20px;
        margin-top: 18px;
        padding: 16px 18px;
        white-space: pre-line;
    }
    .ledger-report-doc__table-wrap {
        border: 1px solid #dce6db;
        border-radius: 18px;
        margin-top: 22px;
        overflow: hidden;
    }
    .ledger-report-doc table {
        border-collapse: collapse;
        width: 100%;
    }
    .ledger-report-doc th,
    .ledger-report-doc td {
        border-bottom: 1px solid #dce6db;
        font-size: 13px;
        padding: 11px 12px;
        text-align: start;
        vertical-align: top;
    }
    .ledger-report-doc thead th {
        background: #e9f2e8;
        color: #31543b;
        font-size: 12px;
        font-weight: 800;
        white-space: nowrap;
    }
    .ledger-report-doc tbody tr:last-child td {
        border-bottom: 0;
    }
    .ledger-report-doc__empty {
        color: #637365;
        padding: 36px;
        text-align: center;
    }
    .ledger-report-doc__footer {
        align-items: flex-end;
        border-top: 1px solid #dce6db;
        display: flex;
        gap: 16px;
        justify-content: space-between;
        margin-top: 22px;
        padding-top: 16px;
    }
    .ledger-report-doc__page-number {
        color: #31543b;
        font-size: 12px;
        font-weight: 700;
        white-space: nowrap;
    }
    @media (max-width: 980px) {
        .ledger-report-doc__header {
            flex-direction: column;
        }
        .ledger-report-doc__brand {
            min-width: 0;
            text-align: start;
        }
        .ledger-report-doc__meta-grid,
        .ledger-report-doc__summary-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .ledger-report-doc__footer {
            flex-direction: column;
            align-items: stretch;
        }
    }
</style>

<div
    class="ledger-report-doc{{ $previewMode ? ' is-preview' : '' }}"
    dir="{{ $dir }}"
    lang="{{ $lang }}"
>
    <div
        class="ledger-report-doc__page"
        style="--ledger-report-background: {{ $backgroundImageUrl ? "url('".$backgroundImageUrl."')" : 'none' }}; --ledger-shape-rgb: {{ $shapeRgb }}; --ledger-shape-opacity: {{ $shapeOpacity }};"
    >
        @if ($backgroundImageUrl)
            <div class="ledger-report-doc__background"></div>
        @endif

        @if ($shapeType)
            <div class="ledger-report-doc__shape ledger-report-doc__shape--{{ $shapeType }}"></div>
        @endif

        <div class="ledger-report-doc__content">
            <header class="ledger-report-doc__header">
                <div>
                    <div class="ledger-report-doc__eyebrow">{{ $template['name'] }}</div>
                    <h1 class="ledger-report-doc__title">{{ $template['title'] }}</h1>
                    @if (! empty($template['subtitle']))
                        <div class="ledger-report-doc__subtitle">{{ $template['subtitle'] }}</div>
                    @endif
                    @if (! empty($template['header_text']))
                        <div class="ledger-report-doc__copy">{{ $template['header_text'] }}</div>
                    @endif
                </div>

                <div class="ledger-report-doc__brand">
                    @if ($logoImageUrl)
                        <div class="ledger-report-doc__logo">
                            <img src="{{ $logoImageUrl }}" alt="{{ $template['title'] }}">
                        </div>
                    @endif
                </div>
            </header>

            <section class="ledger-report-doc__meta-grid">
                <div class="ledger-report-doc__meta-card">
                    <span class="ledger-report-doc__meta-label">{{ $service->bilingual('Cash box', 'الصندوق', $language) }}</span>
                    <span class="ledger-report-doc__meta-value">{{ data_get($report, 'cash_box.name') }}</span>
                </div>
                <div class="ledger-report-doc__meta-card">
                    <span class="ledger-report-doc__meta-label">{{ $service->bilingual('Currency', 'العملة', $language) }}</span>
                    <span class="ledger-report-doc__meta-value">{{ data_get($report, 'currency.code') }} - {{ data_get($report, 'currency.name') }}</span>
                </div>
                <div class="ledger-report-doc__meta-card">
                    <span class="ledger-report-doc__meta-label">{{ $service->bilingual('From date', 'من تاريخ', $language) }}</span>
                    <span class="ledger-report-doc__meta-value">{{ $report['start'] }}</span>
                </div>
                <div class="ledger-report-doc__meta-card">
                    <span class="ledger-report-doc__meta-label">{{ $service->bilingual('To date', 'إلى تاريخ', $language) }}</span>
                    <span class="ledger-report-doc__meta-value">{{ $report['end'] }}</span>
                </div>
                <div class="ledger-report-doc__meta-card">
                    <span class="ledger-report-doc__meta-label">{{ $service->bilingual('Report date', 'تاريخ التقرير', $language) }}</span>
                    <span class="ledger-report-doc__meta-value">{{ $report['report_date'] }}</span>
                </div>
                @if (($template['show_issuer_name'] ?? false) && ! empty($report['issuer_name']))
                    <div class="ledger-report-doc__meta-card">
                        <span class="ledger-report-doc__meta-label">{{ $service->bilingual('Issued by', 'أصدر بواسطة', $language) }}</span>
                        <span class="ledger-report-doc__meta-value">{{ $report['issuer_name'] }}</span>
                    </div>
                @endif
                @if (($template['include_exported_at'] ?? false) && ! empty($report['exported_at']))
                    <div class="ledger-report-doc__meta-card">
                        <span class="ledger-report-doc__meta-label">{{ $service->bilingual('Exported at', 'تاريخ التصدير', $language) }}</span>
                        <span class="ledger-report-doc__meta-value">{{ \Illuminate\Support\Carbon::parse($report['exported_at'])->format('Y-m-d H:i') }}</span>
                    </div>
                @endif
            </section>

            <section class="ledger-report-doc__summary-grid">
                @if ($template['include_opening_balance'] ?? false)
                    <div class="ledger-report-doc__summary-card">
                        <span class="ledger-report-doc__meta-label">{{ $service->bilingual('Opening balance', 'الرصيد الافتتاحي', $language) }}</span>
                        <strong>{{ data_get($report, 'formatted.opening_balance') }}</strong>
                    </div>
                @endif
                <div class="ledger-report-doc__summary-card">
                    <span class="ledger-report-doc__meta-label">{{ $service->bilingual('Income', 'الإيرادات', $language) }}</span>
                    <strong>{{ data_get($report, 'formatted.income') }}</strong>
                </div>
                <div class="ledger-report-doc__summary-card">
                    <span class="ledger-report-doc__meta-label">{{ $service->bilingual('Expense', 'المصاريف', $language) }}</span>
                    <strong>{{ data_get($report, 'formatted.expense') }}</strong>
                </div>
                @if ($template['include_closing_balance'] ?? false)
                    <div class="ledger-report-doc__summary-card">
                        <span class="ledger-report-doc__meta-label">{{ $service->bilingual('Closing balance', 'الرصيد الختامي', $language) }}</span>
                        <strong>{{ data_get($report, 'formatted.closing_balance') }}</strong>
                    </div>
                @endif
            </section>

            @if (! empty($template['custom_text']))
                <section class="ledger-report-doc__custom-text">{{ $template['custom_text'] }}</section>
            @endif

            <section class="ledger-report-doc__table-wrap">
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
                                <td colspan="{{ count($report['columns']) }}" class="ledger-report-doc__empty">{{ __('finance.empty.no_transactions') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </section>

            @if (! empty($template['footer_text']) || ($template['show_page_numbers'] ?? false))
                <footer class="ledger-report-doc__footer">
                    <div class="ledger-report-doc__footer-copy">{{ $template['footer_text'] ?? '' }}</div>
                    @if ($template['show_page_numbers'] ?? false)
                        <div class="ledger-report-doc__page-number">{{ $service->bilingual('Page', 'الصفحة', $language) }} {{ $report['page_number'] ?? 1 }}</div>
                    @endif
                </footer>
            @endif
        </div>
    </div>
</div>
