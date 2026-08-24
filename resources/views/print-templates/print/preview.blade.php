<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ config('app.supported_locales.'.app()->getLocale().'.direction', 'ltr') }}" class="dark">
    <head>
        @include('partials.head', ['title' => $pageTitle ?? __('print_templates.print.preview.title')])
        <style>
            @page { size: {{ $layout['config']['page_width_mm'] }}mm {{ $layout['config']['page_height_mm'] }}mm; margin: 0; }
            body { background: #061109; color: white; padding: 16px; }
            .print-template-toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 20px; }
            .print-template-sheet { width: {{ $layout['config']['page_width_mm'] }}mm; min-height: {{ $layout['config']['page_height_mm'] }}mm; margin: 0 auto 16px; padding: {{ $layout['config']['margin_top_mm'] }}mm {{ $layout['config']['margin_right_mm'] }}mm {{ $layout['config']['margin_bottom_mm'] }}mm {{ $layout['config']['margin_left_mm'] }}mm; background: white; color: #0b1d12; box-shadow: 0 20px 60px rgba(0,0,0,.35); box-sizing: border-box; page-break-after: always; }
            .print-template-grid { display: grid; grid-template-columns: repeat({{ $layout['grid']['columns'] }}, {{ number_format($template->width_mm, 2, '.', '') }}mm); gap: {{ $layout['config']['gap_y_mm'] }}mm {{ $layout['config']['gap_x_mm'] }}mm; align-content: start; justify-content: center; }
            .print-template-render { position: relative; overflow: hidden; border: .2mm solid rgba(15,36,20,.12); border-radius: 0; background-color: #f7fbf8; background-position: center; background-repeat: no-repeat; background-size: cover; box-sizing: border-box; break-inside: avoid; }
            .print-template-render__element { position: absolute; overflow: hidden; box-sizing: border-box; }
            .print-template-render__element--text { display: block; margin: 0; padding: 0; text-indent: 0; white-space: pre-wrap; overflow-wrap: break-word; }
            .print-template-render__element--image { border: .2mm solid rgba(15,36,20,.12); background: #f0f5f2; }
            .print-template-render__image { width: 100%; height: 100%; display: block; }
            .print-template-render__fallback { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; text-align: center; font-size: 2.4mm; color: #38503e; background: rgba(11,143,67,.08); }
            .print-template-render__element--barcode svg { display: block; width: 100%; height: 100%; }
            @media print { body { background: white; padding: 0; } .print-template-toolbar { display: none !important; } .print-template-sheet { box-shadow: none; margin: 0; } }
        </style>
    </head>
    <body @if ($autoPrint ?? false) data-auto-print @endif>
        @unless ($autoPrint ?? false)
            <div class="print-template-toolbar">
                <div>
                    <h1 class="font-display text-4xl text-white">{{ $pageTitle ?? __('print_templates.print.preview.title') }}</h1>
                </div>
                <div class="admin-action-cluster">
                    <button type="button" class="pill-link pill-link--accent" data-print-trigger>{{ $printButtonLabel ?? __('print_templates.print.preview.buttons.print') }}</button>
                    <a href="{{ $backUrl ?? route('print-templates.print.create') }}" class="pill-link">{{ $backButtonLabel ?? __('print_templates.print.preview.buttons.back') }}</a>
                </div>
            </div>
        @endunless

        @foreach ($pages as $pageIndex => $pageItems)
            <section class="print-template-sheet">
                <div class="print-template-grid">
                    @foreach ($pageItems as $item)
                        @include('print-templates.partials.item', ['item' => $item])
                    @endforeach
                </div>
            </section>
        @endforeach

        @if (! empty($studentCardPrintTracking))
            <script type="application/json" id="student-card-print-tracking">@json($studentCardPrintTracking)</script>
        @endif
        <script>
            (() => {
                const printButton = document.querySelector('[data-print-trigger]');
                const trackingNode = document.getElementById('student-card-print-tracking');
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
                    || document.querySelector('input[name="_token"]')?.value;
                let didRecord = false;

                function recordStudentCardPrint() {
                    if (!trackingNode || didRecord || !csrfToken) {
                        return;
                    }

                    didRecord = true;

                    const payload = JSON.parse(trackingNode.textContent);

                    fetch(payload.route, {
                        method: 'POST',
                        credentials: 'same-origin',
                        keepalive: true,
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify({
                            template_id: payload.template_id,
                            student_ids: payload.student_ids,
                            course_id: payload.course_id,
                        }),
                    }).catch(() => {
                        didRecord = false;
                    });
                }

                printButton?.addEventListener('click', () => {
                    recordStudentCardPrint();
                    window.print();
                });

                @if ($autoPrint ?? false)
                    window.addEventListener('load', () => window.print(), { once: true });
                    window.addEventListener('afterprint', () => window.close(), { once: true });
                @endif

                if (!trackingNode) {
                    return;
                }

                window.addEventListener('beforeprint', recordStudentCardPrint);
            })();
        </script>
    </body>
</html>
