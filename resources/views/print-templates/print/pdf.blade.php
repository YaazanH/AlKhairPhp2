<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ config('app.supported_locales.'.app()->getLocale().'.direction', 'ltr') }}">
    <head>
        <meta charset="utf-8">
        <style>@page { margin: 0; } html, body { margin: 0; padding: 0; font-family: dubai, sans-serif; }</style>
    </head>
    <body>
        @php
            $item = $pages[0][0] ?? null;
            $itemLeft = max(0, (float) $layout['config']['margin_left_mm'] + (((float) $layout['config']['page_width_mm'] - (float) $layout['config']['margin_left_mm'] - (float) $layout['config']['margin_right_mm'] - (float) $template->width_mm) / 2));
            $itemTop = (float) $layout['config']['margin_top_mm'];
            $backgroundImageSource = $pdfAssetResolver($template->background_image_url);
        @endphp

        @if ($item)
            <div style="position:fixed;left:{{ $itemLeft }}mm;top:{{ $itemTop }}mm;width:{{ $template->width_mm }}mm;height:{{ $template->height_mm }}mm;overflow:hidden;border:.2mm solid #dce4de;border-radius:{{ $template->rounded_corners ? '2.2mm' : '0' }};background:#f7fbf8;"></div>

            @if ($backgroundImageSource)
                <img src="{{ $backgroundImageSource }}" alt="" style="position:fixed;left:{{ $itemLeft }}mm;top:{{ $itemTop }}mm;width:{{ $template->width_mm }}mm;height:{{ $template->height_mm }}mm;">
            @endif

            @foreach ($item['elements'] as $element)
                @php
                    $elementLeft = $itemLeft + (float) $element['x'];
                    $elementTop = $itemTop + (float) $element['y'];
                    $elementWidth = (float) $element['width'];
                    $elementHeight = (float) $element['height'];
                    $positionStyle = "position:fixed;left:{$elementLeft}mm;top:{$elementTop}mm;width:{$elementWidth}mm;height:{$elementHeight}mm;overflow:hidden;";
                @endphp

                @if (in_array($element['type'], ['dynamic_image', 'static_image'], true))
                    @php $imageSource = $pdfAssetResolver($element['resolved']['src']); @endphp
                    @if ($imageSource)
                        <img src="{{ $imageSource }}" alt="" style="{{ $positionStyle }}border:.2mm solid #dce4de;border-radius:{{ number_format($element['styling']['border_radius'], 2, '.', '') }}mm;">
                    @else
                        <div style="{{ $positionStyle }}background:#edf7f0;color:#38503e;text-align:center;font-size:2.4mm;">{{ $element['resolved']['fallback'] }}</div>
                    @endif
                @elseif ($element['type'] === 'barcode')
                    <div style="{{ $positionStyle }}color:{{ $element['styling']['color'] }};">{!! $element['resolved']['svg'] ?: e($element['resolved']['value'] ?: __('print_templates.common.not_available')) !!}</div>
                @elseif ($element['type'] === 'shape')
                    <div style="{{ $positionStyle }}background:{{ $element['resolved']['fill'] }};opacity:{{ number_format($element['resolved']['opacity'], 2, '.', '') }};border-radius:{{ $element['resolved']['shape_type'] === 'circle' ? '9999px' : '0' }};"></div>
                @else
                    @php
                        $textValue = (string) $element['resolved']['value'];
                        $textAlign = $element['styling']['text_align'];
                        $textDirection = preg_match('/[\p{Arabic}\p{Hebrew}\p{Syriac}]/u', $textValue) === 1 ? 'rtl' : 'ltr';
                    @endphp
                    <div style="{{ $positionStyle }}color:{{ $element['styling']['color'] }};direction:{{ $textDirection }};font-size:{{ number_format($element['styling']['font_size'], 2, '.', '') }}mm;font-weight:{{ $element['styling']['font_weight'] }};text-align:{{ $textAlign }};letter-spacing:{{ number_format($element['styling']['letter_spacing'], 2, '.', '') }}mm;line-height:{{ number_format($element['styling']['line_height'], 2, '.', '') }};">{!! nl2br(e($textValue)) !!}</div>
                @endif
            @endforeach
        @endif
    </body>
</html>
