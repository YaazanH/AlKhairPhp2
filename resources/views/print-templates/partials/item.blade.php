@php
    $template = $item['template'];
    $backgroundImageUrl = $template->background_image_url;
@endphp

<article
    class="print-template-render"
    style="
        width: {{ number_format($template->width_mm, 2, '.', '') }}mm;
        height: {{ number_format($template->height_mm, 2, '.', '') }}mm;
        border-radius: {{ $template->rounded_corners ? '2.2mm' : '0' }};
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    "
>
    @if($backgroundImageUrl)
        <img
            src="{{ $backgroundImageUrl }}"
            alt=""
            aria-hidden="true"
            draggable="false"
            class="print-template-render__background"
            style="position:absolute;inset:0;z-index:0;display:block;width:100%;height:100%;object-fit:cover;"
        >
    @endif

    @foreach ($item['elements'] as $element)
        @php
            $style = sprintf(
                'left:%smm;top:%smm;width:%smm;height:%smm;z-index:%d;',
                number_format($element['x'], 2, '.', ''),
                number_format($element['y'], 2, '.', ''),
                number_format($element['width'], 2, '.', ''),
                number_format($element['height'], 2, '.', ''),
                $element['z_index'],
            );
        @endphp

        @if (in_array($element['type'], ['dynamic_image', 'static_image'], true))
            <div class="print-template-render__element print-template-render__element--image" style="{{ $style }} border-radius: {{ number_format($element['styling']['border_radius'], 2, '.', '') }}mm;">
                @if ($element['resolved']['src'])
                    <img
                        src="{{ $element['resolved']['src'] }}"
                        alt="{{ $element['resolved']['alt'] }}"
                        class="print-template-render__image"
                        style="object-fit: {{ $element['styling']['object_fit'] }};"
                    >
                @else
                    <div class="print-template-render__fallback">
                        {{ $element['resolved']['fallback'] }}
                    </div>
                @endif
            </div>
        @elseif ($element['type'] === 'barcode')
            <div class="print-template-render__element print-template-render__element--barcode" style="{{ $style }} color: {{ $element['styling']['color'] }};">
                {!! $element['resolved']['svg'] ?: '<div class="print-template-render__fallback">'.e($element['resolved']['value'] ?: __('print_templates.common.not_available')).'</div>' !!}
            </div>
        @elseif ($element['type'] === 'shape')
            @php
                $shapeStyle = match ($element['resolved']['shape_type']) {
                    'circle' => 'border-radius:9999px;',
                    'triangle' => 'clip-path:polygon(50% 0, 0 100%, 100% 100%);',
                    default => '',
                };
            @endphp
            <div
                class="print-template-render__element print-template-render__element--shape"
                style="
                    {{ $style }}
                    {{ $shapeStyle }}
                    background: {{ $element['resolved']['fill'] }};
                    opacity: {{ number_format($element['resolved']['opacity'], 2, '.', '') }};
                "
            ></div>
        @else
            @php
                $textValue = (string) $element['resolved']['value'];
                $textAlign = $element['styling']['text_align'];
                $verticalAlign = $element['styling']['vertical_align'] ?? 'top';
                $verticalJustification = match ($verticalAlign) {
                    'center' => 'center',
                    'bottom' => 'flex-end',
                    'justify' => 'space-between',
                    default => 'flex-start',
                };
                $textDirection = preg_match('/[\p{Arabic}\p{Hebrew}\p{Syriac}]/u', $textValue) === 1 ? 'rtl' : 'ltr';
            @endphp
            <div
                class="print-template-render__element print-template-render__element--text"
                style="
                    {{ $style }}
                    display:flex;
                    flex-direction:column;
                    align-items:stretch;
                    justify-content:{{ $verticalJustification }};
                    color: {{ $element['styling']['color'] }};
                    direction: {{ $textDirection }};
                    unicode-bidi: isolate;
                    font-size: {{ number_format($element['styling']['font_size'], 2, '.', '') }}mm;
                    font-weight: {{ $element['styling']['font_weight'] }};
                    text-align: {{ $textAlign }};
                    @if ($textAlign === 'justify')
                        text-align-last: justify;
                        text-justify: auto;
                    @endif
                    text-indent: 0;
                    letter-spacing: {{ number_format($element['styling']['letter_spacing'], 2, '.', '') }}mm;
                    line-height: {{ number_format($element['styling']['line_height'], 2, '.', '') }};
                "
            >{!! $verticalAlign === 'justify'
                ? collect(preg_split('/\R/u', $textValue) ?: [$textValue])->map(fn ($textLine) => '<span style="width:100%">'.e($textLine).'</span>')->implode('')
                : e($textValue) !!}</div>
        @endif
    @endforeach
</article>
