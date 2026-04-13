<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ config('app.supported_locales.'.app()->getLocale().'.direction', 'ltr') }}" class="dark">
    <head>
        @include('partials.head', ['title' => __('id_cards.print.preview.title')])
        <style>
            @page { size: {{ $layout['config']['page_width_mm'] }}mm {{ $layout['config']['page_height_mm'] }}mm; margin: 0; }
            body { background: #061109; color: white; padding: 24px; }
            .id-card-print-toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 24px; }
            .id-card-print-summary__grid { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); margin-bottom: 24px; }
            .id-card-print-sheet { width: {{ $layout['config']['page_width_mm'] }}mm; min-height: {{ $layout['config']['page_height_mm'] }}mm; margin: 0 auto 24px; padding: {{ $layout['config']['margin_top_mm'] }}mm {{ $layout['config']['margin_right_mm'] }}mm {{ $layout['config']['margin_bottom_mm'] }}mm {{ $layout['config']['margin_left_mm'] }}mm; background: white; color: #0b1d12; box-shadow: 0 20px 60px rgba(0,0,0,.35); box-sizing: border-box; page-break-after: always; }
            .id-card-print-grid { display: grid; grid-template-columns: repeat({{ $layout['grid']['columns'] }}, {{ number_format($template->width_mm, 2, '.', '') }}mm); gap: {{ $layout['config']['gap_y_mm'] }}mm {{ $layout['config']['gap_x_mm'] }}mm; align-content: start; }
            .id-card-render { position: relative; overflow: hidden; border: .2mm solid rgba(15,36,20,.12); border-radius: 2.2mm; background-color: #f7fbf8; background-position: center; background-repeat: no-repeat; background-size: cover; box-sizing: border-box; }
            .id-card-render__element { position: absolute; overflow: hidden; box-sizing: border-box; }
            .id-card-render__element--text { display: flex; align-items: center; white-space: nowrap; text-overflow: ellipsis; line-height: 1.12; }
            .id-card-render__element--image { border: .2mm solid rgba(15,36,20,.12); background: #f0f5f2; }
            .id-card-render__image { width: 100%; height: 100%; display: block; }
            .id-card-render__photo-fallback, .id-card-render__barcode-fallback { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; text-align: center; font-size: 2.4mm; color: #38503e; background: rgba(11,143,67,.08); }
            .id-card-render__element--barcode svg { display: block; width: 100%; height: 100%; }
            @media print { body { background: white; padding: 0; } .id-card-print-toolbar, .id-card-print-summary { display: none !important; } .id-card-print-sheet { box-shadow: none; margin: 0; } }
        </style>
    </head>
    <body>
        <div class="id-card-print-toolbar">
            <div>
                <div class="eyebrow">{{ __('ui.nav.identity_tools') }}</div>
                <h1 class="font-display mt-3 text-4xl text-white">{{ __('id_cards.print.preview.title') }}</h1>
                <p class="mt-3 max-w-3xl text-sm leading-7 text-neutral-300">{{ __('id_cards.print.preview.subtitle') }}</p>
            </div>
            <div class="admin-action-cluster">
                <button type="button" class="pill-link pill-link--accent" onclick="window.print()">{{ __('id_cards.print.preview.buttons.print') }}</button>
                <a href="{{ route('id-cards.print.create') }}" class="pill-link">{{ __('id_cards.print.preview.buttons.back') }}</a>
            </div>
        </div>

        <div class="id-card-print-summary">
            @if ($layout['warnings'] !== [])
                <div class="rounded-2xl border border-amber-400/20 bg-amber-500/10 px-4 py-3 text-sm text-amber-100">
                    <ul class="space-y-1 list-disc pl-5">
                        @foreach ($layout['warnings'] as $warning)
                            <li>{{ $warning }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="id-card-print-summary__grid">
                <article class="stat-card"><div class="kpi-label">{{ __('id_cards.print.preview.summary.template') }}</div><div class="mt-4 text-xl font-semibold text-white">{{ $template->name }}</div></article>
                <article class="stat-card"><div class="kpi-label">{{ __('id_cards.print.preview.summary.cards') }}</div><div class="metric-value mt-6">{{ number_format($students->count()) }}</div></article>
                <article class="stat-card"><div class="kpi-label">{{ __('id_cards.print.preview.summary.grid') }}</div><div class="mt-4 text-xl font-semibold text-white">{{ $layout['grid']['columns'] }} × {{ $layout['grid']['rows'] }}</div></article>
                <article class="stat-card"><div class="kpi-label">{{ __('id_cards.print.preview.summary.page_size') }}</div><div class="mt-4 text-xl font-semibold text-white">{{ $layout['config']['page_width_mm'] }} × {{ $layout['config']['page_height_mm'] }} mm</div></article>
            </div>
        </div>

        @foreach ($pages as $pageIndex => $pageCards)
            <section class="id-card-print-sheet">
                <div class="mb-4 text-sm font-semibold text-neutral-500">{{ __('id_cards.print.preview.page_label', ['number' => $pageIndex + 1]) }}</div>
                <div class="id-card-print-grid">
                    @foreach ($pageCards as $card)
                        @include('id-cards.partials.card', ['card' => $card])
                    @endforeach
                </div>
            </section>
        @endforeach
    </body>
</html>
