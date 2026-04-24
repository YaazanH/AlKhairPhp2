<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ config('app.supported_locales.'.app()->getLocale().'.direction', 'ltr') }}" class="dark">
    <head>
        @include('partials.head', ['title' => __('print_templates.print.preview.title')])
        <style>
            @page { size: {{ $layout['config']['page_width_mm'] }}mm {{ $layout['config']['page_height_mm'] }}mm; margin: 0; }
            body { background: #061109; color: white; padding: 24px; }
            .print-template-toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 24px; }
            .print-template-summary__grid { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); margin-bottom: 24px; }
            .print-template-sheet { width: {{ $layout['config']['page_width_mm'] }}mm; min-height: {{ $layout['config']['page_height_mm'] }}mm; margin: 0 auto 24px; padding: {{ $layout['config']['margin_top_mm'] }}mm {{ $layout['config']['margin_right_mm'] }}mm {{ $layout['config']['margin_bottom_mm'] }}mm {{ $layout['config']['margin_left_mm'] }}mm; background: white; color: #0b1d12; box-shadow: 0 20px 60px rgba(0,0,0,.35); box-sizing: border-box; page-break-after: always; }
            .print-template-grid { display: grid; grid-template-columns: repeat({{ $layout['grid']['columns'] }}, {{ number_format($template->width_mm, 2, '.', '') }}mm); gap: {{ $layout['config']['gap_y_mm'] }}mm {{ $layout['config']['gap_x_mm'] }}mm; align-content: start; }
            .print-template-render { position: relative; overflow: hidden; border: .2mm solid rgba(15,36,20,.12); border-radius: 2.2mm; background-color: #f7fbf8; background-position: center; background-repeat: no-repeat; background-size: cover; box-sizing: border-box; break-inside: avoid; }
            .print-template-render__element { position: absolute; overflow: hidden; box-sizing: border-box; }
            .print-template-render__element--text { display: block; white-space: pre-wrap; overflow-wrap: break-word; }
            .print-template-render__element--image { border: .2mm solid rgba(15,36,20,.12); background: #f0f5f2; }
            .print-template-render__image { width: 100%; height: 100%; display: block; }
            .print-template-render__fallback { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; text-align: center; font-size: 2.4mm; color: #38503e; background: rgba(11,143,67,.08); }
            .print-template-render__element--barcode svg { display: block; width: 100%; height: 100%; }
            @media print { body { background: white; padding: 0; } .print-template-toolbar, .print-template-summary { display: none !important; } .print-template-sheet { box-shadow: none; margin: 0; } }
        </style>
    </head>
    <body>
        <div class="print-template-toolbar">
            <div>
                <div class="eyebrow">{{ __('ui.nav.identity_tools') }}</div>
                <h1 class="font-display mt-3 text-4xl text-white">{{ __('print_templates.print.preview.title') }}</h1>
                <p class="mt-3 max-w-3xl text-sm leading-7 text-neutral-300">{{ __('print_templates.print.preview.subtitle') }}</p>
            </div>
            <div class="admin-action-cluster">
                <button type="button" class="pill-link pill-link--accent" onclick="window.print()">{{ __('print_templates.print.preview.buttons.print') }}</button>
                <a href="{{ route('print-templates.print.create') }}" class="pill-link">{{ __('print_templates.print.preview.buttons.back') }}</a>
            </div>
        </div>

        <div class="print-template-summary">
            @if ($layout['warnings'] !== [])
                <div class="mb-6 rounded-2xl border border-amber-400/20 bg-amber-500/10 px-4 py-3 text-sm text-amber-100">
                    <ul class="space-y-1 list-disc pl-5">
                        @foreach ($layout['warnings'] as $warning)
                            <li>{{ $warning }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="print-template-summary__grid">
                <article class="stat-card"><div class="kpi-label">{{ __('print_templates.print.preview.summary.template') }}</div><div class="mt-4 text-xl font-semibold text-white">{{ $template->name }}</div></article>
                <article class="stat-card"><div class="kpi-label">{{ __('print_templates.print.preview.summary.items') }}</div><div class="metric-value mt-6">{{ number_format($totalItems) }}</div></article>
                <article class="stat-card"><div class="kpi-label">{{ __('print_templates.print.preview.summary.grid') }}</div><div class="mt-4 text-xl font-semibold text-white">{{ $layout['grid']['columns'] }} × {{ $layout['grid']['rows'] }}</div></article>
                <article class="stat-card"><div class="kpi-label">{{ __('print_templates.print.preview.summary.page_size') }}</div><div class="mt-4 text-xl font-semibold text-white">{{ $layout['config']['page_width_mm'] }} × {{ $layout['config']['page_height_mm'] }} mm</div></article>
            </div>
        </div>

        @foreach ($pages as $pageIndex => $pageItems)
            <section class="print-template-sheet">
                <div class="mb-4 text-sm font-semibold text-neutral-500">{{ __('id_cards.print.preview.page_label', ['number' => $pageIndex + 1]) }}</div>
                <div class="print-template-grid">
                    @foreach ($pageItems as $item)
                        @include('print-templates.partials.item', ['item' => $item])
                    @endforeach
                </div>
            </section>
        @endforeach
    </body>
</html>
