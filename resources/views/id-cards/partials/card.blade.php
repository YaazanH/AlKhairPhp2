@php
    $template = $card['template'];
    $student = $card['student'];
    $backgroundImageUrl = $template->background_image_url;
@endphp

<article
    class="id-card-render"
    style="
        width: {{ number_format($template->width_mm, 2, '.', '') }}mm;
        height: {{ number_format($template->height_mm, 2, '.', '') }}mm;
        @if($backgroundImageUrl)
            background-image: url('{{ $backgroundImageUrl }}');
        @endif
    "
>
    @foreach ($card['elements'] as $element)
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

        @if ($element['type'] === 'image')
            <div class="id-card-render__element id-card-render__element--image" style="{{ $style }} border-radius: {{ number_format($element['styling']['border_radius'], 2, '.', '') }}mm;">
                @if ($element['resolved']['src'])
                    <img
                        src="{{ $element['resolved']['src'] }}"
                        alt="{{ $element['resolved']['alt'] }}"
                        class="id-card-render__image"
                        style="object-fit: {{ $element['styling']['object_fit'] }};"
                    >
                @else
                    <div class="id-card-render__photo-fallback">
                        {{ $element['resolved']['fallback'] }}
                    </div>
                @endif
            </div>
        @elseif ($element['type'] === 'barcode')
            <div class="id-card-render__element id-card-render__element--barcode" style="{{ $style }} color: {{ $element['styling']['color'] }};">
                {!! $element['resolved']['svg'] ?: '<div class="id-card-render__barcode-fallback">'.e($student->student_number ?: __('id_cards.common.not_available')).'</div>' !!}
            </div>
        @else
            <div
                class="id-card-render__element id-card-render__element--text"
                style="
                    {{ $style }}
                    color: {{ $element['styling']['color'] }};
                    font-size: {{ number_format($element['styling']['font_size'], 2, '.', '') }}mm;
                    font-weight: {{ $element['styling']['font_weight'] }};
                    text-align: {{ $element['styling']['text_align'] }};
                    letter-spacing: {{ number_format($element['styling']['letter_spacing'], 2, '.', '') }}mm;
                "
            >
                {{ $element['resolved']['value'] }}
            </div>
        @endif
    @endforeach
</article>
