<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 8mm; }
        body { color: #17231b; font-family: dubai, sans-serif; font-size: 10px; margin: 0; }
        .calendar-page { height: 279mm; overflow: hidden; position: relative; }
        .calendar-header { margin-bottom: 3.5mm; padding: 0 1mm 2.5mm; }
        .calendar-header__table { border-collapse: collapse; table-layout: fixed; width: 100%; }
        .calendar-header__table td { border: 0; padding: 0; vertical-align: middle; }
        .calendar-header__side { width: 24%; }
        .calendar-header__logo { height: 15mm; max-width: 42mm; object-fit: contain; }
        .calendar-header__title { color: #222; font-size: 23px; font-weight: bold; line-height: 1.1; text-align: center; }
        .calendar-header__dates { color: #5b6b60; direction: ltr; font-size: 10px; text-align: {{ app()->isLocale('ar') ? 'left' : 'right' }}; }
        .months-layout { border-collapse: separate; border-spacing: 3mm 3.2mm; margin: 0; table-layout: fixed; width: 100%; }
        .months-layout > tbody > tr > td { border: 0; padding: 0; vertical-align: top; }
        .month-card { border: 0.7px solid #171717; border-collapse: collapse; table-layout: fixed; width: 100%; }
        .month-title { background: transparent; color: #222; font-size: 18px; font-weight: bold; line-height: 1.05; padding: 0 0 1.8mm; text-align: center; }
        .month-title-spacer { border: 0; border-collapse: collapse; table-layout: fixed; width: 100%; }
        .month-title-spacer td { border: 0; font-size: 1px; height: 0; line-height: 1; padding: 0; }
        .month-card th { background: transparent; border: 0; color: #222; font-family: dubailight, dubai, sans-serif; font-size: 8.5px; font-weight: normal; padding: 0 0.3mm 1mm; text-align: center; }
        .month-card td { border: 0.7px solid #222; height: {{ $calendar['cell_height_mm'] }}mm; padding: 0; position: relative; text-align: center; vertical-align: middle; }
        .month-card__day--outside { background: transparent; color: transparent; }
        .month-card__day--outside-course { color: #879188; }
        .month-card__day--scheduled { background: #b9dfe1; color: #103a3d; font-weight: bold; }
        .month-card__day--start { background: #9ed5c0; color: #0c4932; }
        .month-card__number { direction: ltr; font-size: 11px; line-height: 1; }
        .month-card__marker { display: block; font-size: 10px; font-weight: bold; left: 0; line-height: 1; margin: 0; position: absolute; right: 0; text-align: center; top: {{ max(0, ($calendar['cell_height_mm'] / 2) - 1.35) }}mm; width: 100%; }
        .month-card__marker-line { display: block; }
        .month-card__long-cell-table { border: 0; border-collapse: collapse; direction: ltr; table-layout: fixed; width: 100%; }
        .month-card .month-card__long-cell-side,
        .month-card .month-card__long-cell-number,
        .month-card .month-card__long-cell-overflow { border: 0; height: auto; padding: 0; }
        .month-card .month-card__long-cell-side { width: 20%; }
        .month-card .month-card__long-cell-number { padding-left: 0.6mm; text-align: center; vertical-align: middle; width: 60%; }
        .month-card .month-card__long-cell-overflow { font-size: 12px; font-weight: bold; line-height: 0.7; padding: 0 0.7mm 0 0; text-align: right; vertical-align: top; width: 20%; }
        .month-card__week { background: transparent; border: 0 !important; color: #222; direction: ltr; font-size: 12px; font-weight: normal; width: 7mm; }
        .calendar-page--large .months-layout { margin: 0; width: 100%; }
        .calendar-page--large .month-block { page-break-inside: avoid; width: 100%; }
        .calendar-page--large .month-card { border: 0; border-collapse: collapse; border-spacing: 0; }
        .calendar-page--large .month-title { color: #202020; font-size: 21px; line-height: 1; padding: 0 7mm 0 0; text-align: {{ app()->isLocale('ar') ? 'right' : 'left' }}; }
        .calendar-page--large .month-title-spacer td { height: 3mm; line-height: 3mm; }
        .calendar-page--large .month-card th { color: #222; font-size: 13px; padding: 0 0.4mm 1.6mm; }
        .calendar-page--large .month-card__day-column,
        .calendar-page--large .month-card__weekday { width: 13.75%; }
        .calendar-page--large .month-card__week-column { width: 7mm; }
        .calendar-page--large .month-card__day { width: 13.75%; }
        .calendar-page--large .month-card td { border: 0; padding: 0; text-align: right; vertical-align: top; }
        .calendar-page--large .month-card__day--in-month { border: .55px solid #222; }
        .calendar-page--large .month-card td.month-card__week { vertical-align: middle; }
        .calendar-page--large .month-card__day--outside { background: transparent; border: 0; color: transparent; }
        .calendar-page--large .month-card__number-table { border: 0; border-collapse: collapse; direction: ltr; left: 0; position: absolute; table-layout: fixed; top: 0; width: 100%; z-index: 1; }
        .calendar-page--large .month-card .month-card__number-cell { border: 0; font-family: dubaimedium, dubai, sans-serif; font-size: 10px; font-weight: normal; height: auto; line-height: 1; padding: 0.78mm 0 0; text-align: right; vertical-align: top; }
        .calendar-page--large .month-card .month-card__number-gutter { border: 0; height: auto; padding: 0; width: 0.975mm; }
        .calendar-page--large .month-card__marker-table { border: 0; border-collapse: collapse; height: {{ $calendar['cell_height_mm'] }}mm; left: 0; margin: -0.95mm 0 0; position: absolute; table-layout: fixed; top: 0; width: 100%; }
        .calendar-page--large .month-card .month-card__marker-cell { border: 0; font-size: 15px; font-weight: bold; height: auto; line-height: 1.05; padding: 0 0 0 0.6mm; text-align: center; vertical-align: middle; width: 100%; }
        .calendar-page--large .month-card__day--start .month-card__marker-cell { color: #3f8067; }
        .calendar-page--compact .months-layout { border-spacing: 6mm {{ $calendar['row_count'] >= 4 ? 12.5 : 9 }}mm; }
        .calendar-page--compact .month-card { border: 0; }
        .calendar-page--compact .month-title { font-size: 17px; padding-bottom: 0; }
        .calendar-page--compact .month-title-spacer td { height: 2.5mm; line-height: 2.5mm; }
        .calendar-page--compact .month-card__day-column { width: 14.2857%; }
        .calendar-page--compact .month-card__weekday { height: 4mm; line-height: 4mm; padding: 0; vertical-align: middle; width: 14.2857%; }
        .calendar-page--compact .month-card__day { height: {{ $calendar['cell_height_mm'] }}mm; width: 14.2857%; }
        .calendar-page--compact .month-card__number { color: #5e5e5e; font-size: 14px; font-weight: normal; }
        .calendar-page--compact .month-card__marker { font-family: dubailight, dubai, sans-serif; font-size: 7px; font-weight: normal; line-height: 1.1; }
        .calendar-page--compact .month-card__day--scheduled { background: #fff9bd; color: #545454; font-weight: normal; }
        .calendar-page--compact .month-card__day--weekend,
        .calendar-page--dense .month-card__day--weekend { background: #ecefed; color: #8a918c; }
        .calendar-page--dense .months-layout { border-spacing: 4.8mm {{ $calendar['row_count'] >= 6 ? 5.5 : 10.5 }}mm; }
        .calendar-page--dense .month-card { border: 0; }
        .calendar-page--dense .month-title { font-size: 11px; padding-bottom: 0; }
        .calendar-page--dense .month-title-spacer td { height: 1.5mm; line-height: 1.5mm; }
        .calendar-page--dense .month-card__day-column { width: 14.2857%; }
        .calendar-page--dense .month-card__weekday { height: 3mm; line-height: 3mm; padding: 0; vertical-align: middle; width: 14.2857%; }
        .calendar-page--dense .month-card__day { height: {{ $calendar['cell_height_mm'] }}mm; width: 14.2857%; }
        .calendar-page--dense .month-card__number { color: #5e5e5e; font-size: 9px; font-weight: normal; }
        .calendar-page--dense .month-card__marker { font-family: dubailight, dubai, sans-serif; font-size: 5.5px; font-weight: normal; line-height: 1.05; top: {{ max(0, ($calendar['cell_height_mm'] / 2) - .7) }}mm; }
        .calendar-page--dense .month-card__day--scheduled { background: #fff9bd; color: #545454; font-weight: normal; }
    </style>
</head>
<body>
@php
    $arabic = app()->isLocale('ar');
    $digits = static fn (string|int $value): string => $arabic
        ? strtr((string) $value, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩'])
        : (string) $value;
    $dateText = static fn (\Carbon\CarbonImmutable $date): string => $date->format('d-m-Y');
    $eventTextColor = static function (?string $color): string {
        if (! preg_match('/^#[0-9a-f]{6}$/i', (string) $color)) {
            return '#263d31';
        }

        return sprintf(
            '#%02x%02x%02x',
            (int) round(hexdec(substr($color, 1, 2)) * 0.52),
            (int) round(hexdec(substr($color, 3, 2)) * 0.52),
            (int) round(hexdec(substr($color, 5, 2)) * 0.52),
        );
    };
    $eventCellStyle = static function (?string $color) use ($eventTextColor): string {
        if (! preg_match('/^#[0-9a-f]{6}$/i', (string) $color)) {
            return '';
        }

        return sprintf(
            'background-color: rgba(%d, %d, %d, 0.24); color: %s;',
            hexdec(substr($color, 1, 2)),
            hexdec(substr($color, 3, 2)),
            hexdec(substr($color, 5, 2)),
            $eventTextColor($color),
        );
    };
    $courseName = trim($course->name);
    $courseTitle = __('course_calendar.title', ['course' => $courseName]);
    $monthColumnWidth = match ($calendar['columns']) {
        1 => '100%',
        2 => '50%',
        default => '33.3333%',
    };
    $dayColumnWidth = $calendar['layout'] === 'large' ? '13.75%' : '14.2857%';
    $markerCharacterLimit = match ($calendar['layout']) {
        'large' => 12,
        'compact' => 10,
        default => $calendar['columns'] === 3 ? 7 : 10,
    };
@endphp

@foreach($calendar['pages'] as $pageIndex => $months)
    @if($pageIndex > 0)<pagebreak />@endif
    <section class="calendar-page calendar-page--{{ $calendar['layout'] }}">
        <header class="calendar-header">
            <table class="calendar-header__table" dir="ltr">
                <tr>
                    <td class="calendar-header__side calendar-header__dates">
                        {{ $dateText($calendar['starts_on']) }}<br>{{ $dateText($calendar['ends_on']) }}
                    </td>
                    <td class="calendar-header__title" dir="{{ $arabic ? 'rtl' : 'ltr' }}">
                        {{ $courseTitle }}
                    </td>
                    <td class="calendar-header__side" style="text-align:right">
                        @if($logo)<img class="calendar-header__logo" src="{{ $logo }}" alt="">@endif
                    </td>
                </tr>
            </table>
        </header>

        @if($calendar['layout'] === 'large')
        <div class="months-layout months-layout--large">
        @else
        <table class="months-layout">
            <colgroup>
                @for($monthColumn = 0; $monthColumn < $calendar['columns']; $monthColumn++)<col width="{{ $monthColumnWidth }}">@endfor
            </colgroup>
            <tbody>
        @endif
            @foreach(array_chunk($months, $calendar['columns']) as $monthRow)
                @php $monthRowIndex = $loop->index; @endphp
                @if($calendar['layout'] !== 'large')
                <tr>
                @endif
                    @foreach($monthRow as $month)
                        @php
                            $monthCellHeight = (float) ($month['cell_height_mm'] ?? $calendar['cell_height_mm']);
                            $monthMarkerTop = max(0, ($monthCellHeight / 2) - ($calendar['layout'] === 'dense' ? 0.7 : 1.35));
                            $largeMonthGap = match ($calendar['row_count']) { 2 => 20, 3 => 15, default => 0 };
                        @endphp
                        @if($calendar['layout'] === 'large')
                        <div class="month-block" @if($monthRowIndex < $calendar['row_count'] - 1) style="margin-bottom: {{ $largeMonthGap }}mm;" @endif>
                        @else
                        <td width="{{ $monthColumnWidth }}">
                            <div class="month-block">
                        @endif
                            <div class="month-title">{{ __('course_calendar.months.'.$month['name']) }} {{ $digits($month['year']) }}</div>
                            <table class="month-title-spacer" aria-hidden="true"><tr><td>&nbsp;</td></tr></table>
                            <table class="month-card" dir="{{ $arabic ? 'rtl' : 'ltr' }}">
                                <colgroup>
                                    @if($calendar['layout'] === 'large' && $arabic)<col class="month-card__week-column">@endif
                                    @for($dayColumn = 0; $dayColumn < 7; $dayColumn++)<col class="month-card__day-column" width="{{ $dayColumnWidth }}">@endfor
                                    @if($calendar['layout'] === 'large' && ! $arabic)<col class="month-card__week-column">@endif
                                </colgroup>
                                <thead>
                                    <tr>
                                        @if($calendar['layout'] === 'large' && $arabic)<th class="month-card__week" width="7mm"></th>@endif
                                        <th class="month-card__weekday" width="{{ $dayColumnWidth }}">{{ __('course_calendar.weekdays.sunday') }}</th>
                                        <th class="month-card__weekday" width="{{ $dayColumnWidth }}">{{ __('course_calendar.weekdays.monday') }}</th>
                                        <th class="month-card__weekday" width="{{ $dayColumnWidth }}">{{ __('course_calendar.weekdays.tuesday') }}</th>
                                        <th class="month-card__weekday" width="{{ $dayColumnWidth }}">{{ __('course_calendar.weekdays.wednesday') }}</th>
                                        <th class="month-card__weekday" width="{{ $dayColumnWidth }}">{{ __('course_calendar.weekdays.thursday') }}</th>
                                        <th class="month-card__weekday" width="{{ $dayColumnWidth }}">{{ __('course_calendar.weekdays.friday') }}</th>
                                        <th class="month-card__weekday" width="{{ $dayColumnWidth }}">{{ __('course_calendar.weekdays.saturday') }}</th>
                                        @if($calendar['layout'] === 'large' && ! $arabic)<th class="month-card__week" width="7mm"></th>@endif
                                    </tr>
                                </thead>
                                <tbody>
                                @foreach($month['weeks'] as $week)
                                    <tr>
                                        @if($calendar['layout'] === 'large' && $arabic)<td class="month-card__week" width="7mm">{{ $week['number'] ? $digits($week['number']) : '' }}</td>@endif
                                        @foreach($week['days'] as $day)
                                            @php
                                                $classes = [];
                                                $hasCalendarEvent = $day['is_start'] || ! empty($day['comments']);
                                                if (! $day['in_month']) $classes[] = 'month-card__day--outside';
                                                else $classes[] = 'month-card__day--in-month';
                                                if ($day['in_month'] && ! $day['in_course']) $classes[] = 'month-card__day--outside-course';
                                                if ($calendar['layout'] !== 'large' && $day['in_month'] && in_array($day['date']->dayOfWeek, [5, 6], true) && ! $day['scheduled'] && ! $hasCalendarEvent) $classes[] = 'month-card__day--weekend';
                                                if ($day['in_month'] && $day['scheduled']) $classes[] = 'month-card__day--scheduled';
                                                if ($day['in_month'] && $day['is_start']) $classes[] = 'month-card__day--start';
                                                $eventColor = $day['comments'][0]['color'] ?? null;
                                                $markerLines = [];

                                                if ($day['is_start']) {
                                                    $markerLines[] = ['text' => __('course_calendar.start'), 'color' => null];
                                                }

                                                foreach ($day['comments'] ?? [] as $comment) {
                                                    $markerLines[] = ['text' => $comment['name'], 'color' => $comment['color']];
                                                }

                                                $visibleMarkerLines = [];
                                                $hasOverflowMarker = false;

                                                foreach ($markerLines as $markerLine) {
                                                    if (mb_strlen(trim($markerLine['text'])) > $markerCharacterLimit) {
                                                        $hasOverflowMarker = true;
                                                    } else {
                                                        $visibleMarkerLines[] = $markerLine;
                                                    }
                                                }

                                                $dayCellStyle = $eventCellStyle($eventColor);

                                                if ($calendar['layout'] !== 'large') {
                                                    $dayCellStyle .= ' height: '.$monthCellHeight.'mm;';
                                                }
                                            @endphp
                                            <td class="month-card__day {{ implode(' ', $classes) }}" width="{{ $dayColumnWidth }}" @if($dayCellStyle !== '') style="{{ $dayCellStyle }}" @endif @if($hasOverflowMarker) data-calendar-overflow-marker @endif>
                                                @if($calendar['layout'] === 'large')
                                                    <table class="month-card__number-table" dir="ltr"><tr><td class="month-card__number-cell">@if($hasOverflowMarker)<span data-calendar-overflow-marker>*</span>&nbsp;@endif{{ $day['in_month'] ? $digits($day['date']->day) : '' }}</td><td class="month-card__number-gutter"></td></tr></table>
                                                @else
                                                    <table class="month-card__long-cell-table" dir="ltr" style="height: {{ max(1, $monthCellHeight - 0.5) }}mm;"><tr>
                                                        <td class="month-card__long-cell-side" width="20%">&nbsp;</td>
                                                        <td class="month-card__long-cell-number" width="60%" align="center" valign="middle"><span class="month-card__number">{{ $day['in_month'] ? $digits($day['date']->day) : '' }}</span></td>
                                                        <td class="month-card__long-cell-overflow" width="20%" align="right" valign="top">@if($hasOverflowMarker)<span data-calendar-overflow-marker>*</span>@endif</td>
                                                    </tr></table>
                                                @endif
                                                @if($day['in_month'] && $visibleMarkerLines !== [])
                                                    @if($calendar['layout'] === 'large')
                                                        <table class="month-card__marker-table"><tr><td class="month-card__marker-cell" align="center">
                                                            @foreach($visibleMarkerLines as $markerLine)<span class="month-card__marker-line" @if($markerLine['color']) style="color: {{ $eventTextColor($markerLine['color']) }}" @endif>{{ $markerLine['text'] }}</span>@endforeach
                                                        </td></tr></table>
                                                    @else
                                                        <span class="month-card__marker" style="top: {{ $monthMarkerTop }}mm;">
                                                            @foreach($visibleMarkerLines as $markerLine)<span class="month-card__marker-line" @if($markerLine['color']) style="color: {{ $eventTextColor($markerLine['color']) }}" @endif>{{ $markerLine['text'] }}</span>@endforeach
                                                        </span>
                                                    @endif
                                                @endif
                                            </td>
                                        @endforeach
                                        @if($calendar['layout'] === 'large' && ! $arabic)<td class="month-card__week" width="7mm">{{ $week['number'] ? $digits($week['number']) : '' }}</td>@endif
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                            </div>
                        @if($calendar['layout'] !== 'large')
                        </td>
                        @endif
                    @endforeach
                    @if($calendar['layout'] !== 'large')
                    @for($emptyColumn = count($monthRow); $emptyColumn < $calendar['columns']; $emptyColumn++)<td width="{{ $monthColumnWidth }}"></td>@endfor
                </tr>
                    @endif
            @endforeach
        @if($calendar['layout'] === 'large')
        </div>
        @else
            </tbody>
        </table>
        @endif
    </section>
@endforeach
</body>
</html>
