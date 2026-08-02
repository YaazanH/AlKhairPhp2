<table class="meta-table">
    <tr>
        <td class="meta-label">العام الدراسي</td>
        <td class="meta-value">{{ $report['academic_year'] ?? '-' }}</td>
        <td class="meta-label">تاريخ بداية التقرير</td>
        <td class="meta-value">{{ \Illuminate\Support\Carbon::parse($report['start'])->format('d-m-Y') }}</td>
        <td class="qr-cell" rowspan="2"><barcode code="{{ $qrPayload }}" type="QR" size="0.75" error="M" /></td>
    </tr>
    <tr>
        <td class="meta-label">الرصيد الافتتاحي</td>
        <td class="meta-value" dir="ltr">{{ data_get($report, 'formatted.opening_balance') }}</td>
        <td class="meta-label">تاريخ نهاية التقرير</td>
        <td class="meta-value">{{ \Illuminate\Support\Carbon::parse($report['end'])->format('d-m-Y') }}</td>
    </tr>
</table>
