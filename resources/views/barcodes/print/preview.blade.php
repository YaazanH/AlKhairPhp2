<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ config('app.supported_locales.'.app()->getLocale().'.direction', 'ltr') }}" class="dark">
    <head>
        @include('partials.head', ['title' => __('barcodes.print.preview.title')])
        <style>
            @page { size: {{ $layout['config']['page_width_mm'] }}mm {{ $layout['config']['page_height_mm'] }}mm; margin: 0; }
            body { background: #061109; color: white; padding: 24px; }
            .barcode-print-toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 24px; }
            .barcode-print-sheet { width: {{ $layout['config']['page_width_mm'] }}mm; min-height: {{ $layout['config']['page_height_mm'] }}mm; margin: 0 auto 24px; padding: {{ $layout['config']['margin_top_mm'] }}mm {{ $layout['config']['margin_right_mm'] }}mm {{ $layout['config']['margin_bottom_mm'] }}mm {{ $layout['config']['margin_left_mm'] }}mm; background: white; color: #0b1d12; box-shadow: 0 20px 60px rgba(0,0,0,.35); box-sizing: border-box; page-break-after: always; }
            .barcode-print-grid { display: grid; grid-template-columns: repeat({{ $layout['grid']['columns'] }}, {{ number_format($label['width_mm'], 2, '.', '') }}mm); gap: {{ $layout['config']['gap_y_mm'] }}mm {{ $layout['config']['gap_x_mm'] }}mm; align-content: start; }
            .barcode-action-label { width: {{ number_format($label['width_mm'], 2, '.', '') }}mm; height: {{ number_format($label['height_mm'], 2, '.', '') }}mm; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 2mm; border: .2mm solid rgba(15,36,20,.18); border-radius: 2mm; padding: 3mm; box-sizing: border-box; break-inside: avoid; }
            .barcode-action-label__svg { width: 100%; height: 62%; color: #0f2414; }
            .barcode-action-label__svg svg { width: 100%; height: 100%; display: block; }
            .barcode-action-label__name { font-size: 4mm; font-weight: 800; text-align: center; line-height: 1.05; color: #0f2414; }
            .barcode-action-label__code { font-size: 2.3mm; font-weight: 700; letter-spacing: .12mm; text-align: center; color: #47614f; }
            @media print { body { background: white; padding: 0; } .barcode-print-toolbar, .barcode-print-summary { display: none !important; } .barcode-print-sheet { box-shadow: none; margin: 0; } }
        </style>
    </head>
    <body>
        <div class="barcode-print-toolbar">
            <div>
                <div class="eyebrow">{{ __('ui.nav.identity_tools') }}</div>
                <h1 class="font-display mt-3 text-4xl text-white">{{ __('barcodes.print.preview.title') }}</h1>
                <p class="mt-3 max-w-3xl text-sm leading-7 text-neutral-300">{{ __('barcodes.print.preview.subtitle') }}</p>
            </div>
            <div class="admin-action-cluster">
                <button type="button" class="pill-link pill-link--accent" onclick="window.print()">{{ __('barcodes.print.preview.buttons.print') }}</button>
                <a href="{{ route('barcode-actions.index') }}" class="pill-link">{{ __('barcodes.print.preview.buttons.back') }}</a>
            </div>
        </div>

        @if ($layout['warnings'] !== [])
            <div class="barcode-print-summary mb-6 rounded-2xl border border-amber-400/20 bg-amber-500/10 px-4 py-3 text-sm text-amber-100">
                <ul class="list-disc space-y-1 pl-5">
                    @foreach ($layout['warnings'] as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @foreach ($pages as $pageIndex => $pageActions)
            <section class="barcode-print-sheet">
                <div class="mb-4 text-sm font-semibold text-neutral-500">{{ __('id_cards.print.preview.page_label', ['number' => $pageIndex + 1]) }}</div>
                <div class="barcode-print-grid">
                    @foreach ($pageActions as $labelAction)
                        <article class="barcode-action-label">
                            <div class="barcode-action-label__svg">{!! $labelAction['svg'] !!}</div>
                            <div class="barcode-action-label__name">{{ $labelAction['action']->name }}</div>
                            <div class="barcode-action-label__code">{{ $labelAction['action']->code }}</div>
                        </article>
                    @endforeach
                </div>
            </section>
        @endforeach
    </body>
</html>
