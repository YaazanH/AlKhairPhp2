@php($isRtl = app()->isLocale('ar'))
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? __('print.layout.title') }}</title>
    <style>
        :root {
            color-scheme: light;
        }
        * {
            box-sizing: border-box;
        }
        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            color: #111827;
            background: #f5f5f4;
            direction: {{ $isRtl ? 'rtl' : 'ltr' }};
        }
        .page {
            max-width: 960px;
            margin: 24px auto;
            background: #ffffff;
            border: 1px solid #e7e5e4;
            border-radius: 18px;
            padding: 32px;
            box-shadow: 0 10px 30px rgba(12, 10, 9, 0.08);
        }
        .toolbar {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 24px;
        }
        .print-button {
            border: 1px solid #d6d3d1;
            border-radius: 999px;
            background: #111827;
            color: #ffffff;
            padding: 10px 18px;
            font-size: 14px;
            cursor: pointer;
        }
        .header {
            display: flex;
            justify-content: space-between;
            gap: 24px;
            padding-bottom: 24px;
            border-bottom: 1px solid #e7e5e4;
        }
        .title {
            margin: 0;
            font-size: 32px;
            line-height: 1.1;
        }
        .subtitle {
            margin-top: 8px;
            color: #57534e;
            font-size: 14px;
        }
        .section {
            margin-top: 28px;
        }
        .section h2 {
            margin: 0 0 12px;
            font-size: 18px;
        }
        .meta-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }
        .meta-card {
            border: 1px solid #e7e5e4;
            border-radius: 14px;
            padding: 14px 16px;
            background: #fafaf9;
        }
        .meta-label {
            display: block;
            font-size: 12px;
            color: #78716c;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }
        .meta-value {
            margin-top: 6px;
            font-size: 16px;
            font-weight: 600;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px 14px;
            border-bottom: 1px solid #e7e5e4;
            text-align: start;
            vertical-align: top;
            font-size: 14px;
        }
        thead th {
            font-size: 12px;
            color: #57534e;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            background: #fafaf9;
        }
        .totals {
            margin-top: 16px;
            margin-left: auto;
            width: min(320px, 100%);
        }
        .totals-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e7e5e4;
            font-size: 14px;
        }
        .totals-row strong {
            font-size: 16px;
        }
        .note-box {
            border: 1px dashed #d6d3d1;
            border-radius: 14px;
            padding: 14px 16px;
            background: #fafaf9;
            font-size: 14px;
            white-space: pre-wrap;
        }
        @media print {
            body {
                background: #ffffff;
            }
            .page {
                margin: 0;
                max-width: none;
                border: 0;
                border-radius: 0;
                box-shadow: none;
                padding: 0;
            }
            .toolbar {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="toolbar">
            <button type="button" class="print-button" onclick="window.print()">{{ __('print.layout.button') }}</button>
        </div>

        {{ $slot }}
    </div>
</body>
</html>
