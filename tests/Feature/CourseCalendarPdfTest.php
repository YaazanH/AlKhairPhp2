<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseCalendarEntry;
use App\Models\CourseSchedule;
use App\Services\CourseCalendarService;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Tests\TestCase;

class CourseCalendarPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_short_and_long_courses_use_the_expected_calendar_layouts(): void
    {
        $service = app(CourseCalendarService::class);

        $shortCourse = new Course([
            'name' => 'Summer 2026',
            'starts_on' => '2026-06-01',
            'ends_on' => '2026-08-31',
        ]);
        $shortCourse->setRelation('schedules', collect([
            new CourseSchedule(['day_of_week' => 2, 'time_slot' => 'morning']),
            new CourseSchedule(['day_of_week' => 4, 'time_slot' => 'morning']),
        ]));

        $shortCalendar = $service->build($shortCourse);

        $this->assertSame('large', $shortCalendar['layout']);
        $this->assertSame(11.1, $shortCalendar['cell_height_mm']);
        $this->assertCount(1, $shortCalendar['pages']);
        $this->assertCount(3, $shortCalendar['pages'][0]);

        $juneDays = collect($shortCalendar['pages'][0][0]['weeks'])->pluck('days')->flatten(1);
        $this->assertTrue($juneDays->firstWhere(fn (array $day): bool => $day['date']->isSameDay('2026-06-02'))['scheduled']);
        $this->assertFalse($juneDays->firstWhere(fn (array $day): bool => $day['date']->isSameDay('2026-06-03'))['scheduled']);
        $this->assertTrue($juneDays->firstWhere(fn (array $day): bool => $day['date']->isSameDay('2026-06-01'))['is_start']);
        $julyOutsideDays = collect($shortCalendar['pages'][0][1]['weeks'])
            ->pluck('days')
            ->flatten(1)
            ->where('in_month', false);
        $this->assertFalse($julyOutsideDays->contains('scheduled', true));

        $longCourse = new Course([
            'name' => 'Winter 2026',
            'starts_on' => '2025-10-01',
            'ends_on' => '2026-05-31',
        ]);
        $longCourse->setRelation('schedules', collect());

        $longCalendar = $service->build($longCourse);

        $this->assertSame('compact', $longCalendar['layout']);
        $this->assertSame(4, $longCalendar['row_count']);
        $this->assertCount(1, $longCalendar['pages']);
        $this->assertCount(8, $longCalendar['pages'][0]);
        $hasUnequalCompactPair = false;
        foreach (array_chunk($longCalendar['pages'][0], 2) as $monthPair) {
            $weekCounts = array_map(fn (array $month): int => count($month['weeks']), $monthPair);
            $hasUnequalCompactPair = $hasUnequalCompactPair || count(array_unique($weekCounts)) > 1;
            $tableHeights = array_map(
                fn (array $month): float => count($month['weeks']) * $month['cell_height_mm'],
                $monthPair,
            );
            $this->assertEqualsWithDelta($tableHeights[0], $tableHeights[1], 0.001);
        }
        $this->assertTrue($hasUnequalCompactPair);
    }

    public function test_very_long_courses_are_condensed_onto_one_page(): void
    {
        $course = new Course([
            'name' => 'Extended course',
            'starts_on' => '2025-09-01',
            'ends_on' => '2026-06-30',
        ]);
        $course->setRelation('schedules', collect());

        $calendar = app(CourseCalendarService::class)->build($course);

        $this->assertSame('dense', $calendar['layout']);
        $this->assertSame(2, $calendar['columns']);
        $this->assertCount(1, $calendar['pages']);
        $this->assertCount(10, $calendar['pages'][0]);
        foreach (array_chunk($calendar['pages'][0], 2) as $monthPair) {
            $tableHeights = array_map(
                fn (array $month): float => count($month['weeks']) * $month['cell_height_mm'],
                $monthPair,
            );
            $this->assertEqualsWithDelta($tableHeights[0], $tableHeights[1], 0.001);
        }
    }

    public function test_months_with_only_one_scheduled_course_day_are_omitted(): void
    {
        $course = new Course([
            'name' => 'Boundary month course',
            'starts_on' => '2026-06-30',
            'ends_on' => '2026-08-31',
        ]);
        $course->setRelation('schedules', collect([
            new CourseSchedule(['day_of_week' => 2, 'time_slot' => 'morning']),
        ]));

        $calendar = app(CourseCalendarService::class)->build($course);

        $this->assertSame(['july', 'august'], array_column($calendar['pages'][0], 'name'));
        $this->assertSame('large', $calendar['layout']);

        $course->setRelation('calendarEntries', collect([
            new CourseCalendarEntry([
                'date' => '2026-06-30',
                'name' => 'Orientation',
                'color' => '#245c46',
            ]),
        ]));
        $calendarWithComment = app(CourseCalendarService::class)->build($course);
        $june = collect($calendarWithComment['pages'][0])->firstWhere('name', 'june');
        $juneComment = collect($june['weeks'])->pluck('days')->flatten(1)
            ->firstWhere(fn (array $day): bool => $day['date']->isSameDay('2026-06-30'))['comments'][0];

        $this->assertSame(['june', 'july', 'august'], array_column($calendarWithComment['pages'][0], 'name'));
        $this->assertSame(['name' => 'Orientation', 'color' => '#245c46'], $juneComment);

        $singleDayCourse = new Course([
            'name' => 'Single day course',
            'starts_on' => '2026-06-30',
            'ends_on' => '2026-06-30',
        ]);
        $singleDayCourse->setRelation('schedules', collect([
            new CourseSchedule(['day_of_week' => 2, 'time_slot' => 'morning']),
        ]));

        $singleDayCalendar = app(CourseCalendarService::class)->build($singleDayCourse);

        $this->assertSame(['june'], array_column($singleDayCalendar['pages'][0], 'name'));
    }

    public function test_course_calendar_route_returns_an_inline_pdf(): void
    {
        $course = Course::create([
            'name' => 'Extended PDF Calendar Course',
            'starts_on' => '2025-09-01',
            'ends_on' => '2026-06-30',
            'is_active' => true,
        ]);
        CourseSchedule::create([
            'course_id' => $course->id,
            'day_of_week' => 2,
            'time_slot' => 'morning',
        ]);
        CourseCalendarEntry::create([
            'course_id' => $course->id,
            'date' => '2026-02-10',
            'name' => 'Special event',
            'color' => '#a37326',
        ]);

        $response = $this
            ->withoutMiddleware([
                Authenticate::class,
                PermissionMiddleware::class,
            ])
            ->get(route('courses.calendar.pdf', $course));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('inline;', (string) $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertSame(1, preg_match_all('~/Type\s*/Page\b~', $response->getContent()));
    }

    public function test_overlong_calendar_text_uses_an_asterisk_without_resizing_day_columns(): void
    {
        app()->setLocale('ar');

        $course = new Course([
            'name' => 'دورة اختبار التقويم',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-04-30',
        ]);
        $course->setRelation('schedules', collect());
        $course->setRelation('calendarEntries', collect([
            new CourseCalendarEntry([
                'date' => '2026-10-15',
                'name' => 'اسم فعالية طويل لا يتسع داخل الخلية',
                'color' => '#a37326',
            ]),
        ]));

        $calendar = app(CourseCalendarService::class)->build($course);
        $html = view('reports.course-calendar', [
            'course' => $course,
            'calendar' => $calendar,
            'logo' => null,
        ])->render();

        $this->assertStringContainsString('data-calendar-overflow-marker', $html);
        $this->assertStringNotContainsString('اسم فعالية طويل لا يتسع داخل الخلية', $html);
        $this->assertStringContainsString('width="14.2857%"', $html);
    }

    public function test_course_calendar_action_sits_beside_edit_and_opens_its_own_manager(): void
    {
        $source = file_get_contents(resource_path('views/livewire/courses/index.blade.php'));
        $editPosition = strpos($source, 'data-course-edit-action');
        $calendarPosition = strpos($source, 'data-course-calendar-action');

        $this->assertNotFalse($editPosition);
        $this->assertNotFalse($calendarPosition);
        $this->assertGreaterThan($editPosition, $calendarPosition);
        $this->assertStringNotContainsString('data-course-form-calendar-action', $source);
        $this->assertStringContainsString('wire:click="openCourseCalendar({{ $course->id }})"', $source);
        $this->assertStringContainsString("route('courses.calendar.pdf', \$calendarCourseId)", $source);
        $this->assertStringContainsString("title=\"{{ __('crud.courses.actions.calendar') }}\"", $source);
        $this->assertStringContainsString("aria-label=\"{{ __('crud.courses.actions.calendar') }}\"", $source);
        $this->assertStringContainsString('<x-admin-action-icon name="calendar"', $source);
        $this->assertStringContainsString('data-course-calendar-save', $source);
        $this->assertStringContainsString('wire:click="saveCourseCalendar" class="admin-modal__close"', $source);
        $this->assertStringContainsString('<span aria-hidden="true">&times;</span>', $source);
        $this->assertStringContainsString('data-course-calendar-pdf-action', $source);
        $this->assertStringNotContainsString('close-method="closeCourseCalendar"', $source);
        $this->assertGreaterThan(
            strpos($source, 'data-course-calendar-pdf-action'),
            strpos($source, 'data-course-calendar-save'),
        );
        $this->assertStringContainsString('data-course-calendar-entry-table', $source);
        $this->assertStringContainsString('data-course-calendar-entry-add-row', $source);
        $this->assertStringContainsString('data-course-calendar-entry-update', $source);
        $this->assertStringContainsString('wire:model="calendarDate"', $source);
        $this->assertStringContainsString('wire:model="calendarName"', $source);
        $this->assertStringContainsString('wire:model="calendarColor"', $source);

        $calendarTemplate = file_get_contents(resource_path('views/reports/course-calendar.blade.php'));
        $this->assertStringContainsString("\$courseTitle = __('course_calendar.title', ['course' => \$courseName]);", $calendarTemplate);
        $this->assertStringNotContainsString('course_calendar.named_title', $calendarTemplate);
        $this->assertStringContainsString("2 => '50%'", $calendarTemplate);
        $this->assertStringContainsString('<col width="{{ $monthColumnWidth }}">', $calendarTemplate);
        $this->assertStringContainsString('<td width="{{ $monthColumnWidth }}">', $calendarTemplate);
        $this->assertStringContainsString('.calendar-page--large .month-card { border: 0; border-collapse: collapse; border-spacing: 0; }', $calendarTemplate);
        $this->assertStringContainsString('.calendar-page--large .month-card__day--outside { background: transparent; border: 0; color: transparent; }', $calendarTemplate);
        $this->assertStringContainsString('.calendar-page--large .month-title { color: #202020; font-size: 21px;', $calendarTemplate);
        $this->assertStringContainsString('padding: 0 7mm 0 0;', $calendarTemplate);
        $this->assertStringContainsString('.calendar-page--large .month-title-spacer td { height: 3mm; line-height: 3mm; }', $calendarTemplate);
        $this->assertStringContainsString('.calendar-page--large .month-block { page-break-inside: avoid; width: 100%; }', $calendarTemplate);
        $this->assertStringContainsString("\$largeMonthGap = match (\$calendar['row_count']) { 2 => 20, 3 => 15, default => 0 };", $calendarTemplate);
        $this->assertStringContainsString('<div class="month-block" @if($monthRowIndex < $calendar[\'row_count\'] - 1) style="margin-bottom: {{ $largeMonthGap }}mm;" @endif>', $calendarTemplate);
        $this->assertStringContainsString('.calendar-page--large .month-card__weekday { width: 13.75%; }', $calendarTemplate);
        $this->assertStringContainsString('.calendar-page--large .month-card__day { width: 13.75%; }', $calendarTemplate);
        $this->assertStringContainsString('.calendar-page--large .month-card__week-column { width: 7mm; }', $calendarTemplate);
        $this->assertStringContainsString('@for($dayColumn = 0; $dayColumn < 7; $dayColumn++)<col class="month-card__day-column" width="{{ $dayColumnWidth }}">@endfor', $calendarTemplate);
        $this->assertStringContainsString('class="month-card__weekday" width="{{ $dayColumnWidth }}"', $calendarTemplate);
        $this->assertStringContainsString('class="month-card__day {{ implode(\' \', $classes) }}" width="{{ $dayColumnWidth }}"', $calendarTemplate);
        $this->assertStringContainsString('.calendar-page--large .month-card__number-table { border: 0; border-collapse: collapse; direction: ltr;', $calendarTemplate);
        $this->assertStringContainsString('position: absolute; table-layout: fixed; top: 0; width: 100%;', $calendarTemplate);
        $this->assertStringContainsString('.calendar-page--large .month-card .month-card__number-cell { border: 0; font-family: dubaimedium, dubai, sans-serif; font-size: 10px;', $calendarTemplate);
        $this->assertStringContainsString('padding: 0.78mm 0 0; text-align: right; vertical-align: top;', $calendarTemplate);
        $this->assertStringContainsString('.calendar-page--large .month-card .month-card__number-gutter { border: 0; height: auto; padding: 0; width: 0.975mm; }', $calendarTemplate);
        $this->assertStringContainsString('.calendar-page--large .month-card__marker-table { border: 0; border-collapse: collapse; height: {{ $calendar[\'cell_height_mm\'] }}mm;', $calendarTemplate);
        $this->assertStringContainsString('margin: -0.95mm 0 0; position: absolute;', $calendarTemplate);
        $this->assertStringContainsString('class="month-card__marker-cell" align="center"', $calendarTemplate);
        $this->assertStringContainsString('padding: 0 0 0 0.6mm; text-align: center; vertical-align: middle;', $calendarTemplate);
        $this->assertStringContainsString('.calendar-page--large .month-card__day--start .month-card__marker-cell { color: #3f8067; }', $calendarTemplate);
        $this->assertStringNotContainsString('.month-card__day--end', $calendarTemplate);
        $this->assertStringContainsString('font-family: dubailight, dubai, sans-serif; font-size: 8.5px; font-weight: normal;', $calendarTemplate);
        $this->assertStringContainsString('.calendar-page--compact .months-layout { border-spacing: 6mm {{ $calendar[\'row_count\'] >= 4 ? 12.5 : 9 }}mm; }', $calendarTemplate);
        $this->assertStringContainsString('.calendar-page--compact .month-title { font-size: 17px; padding-bottom: 0; }', $calendarTemplate);
        $this->assertStringContainsString('.calendar-page--compact .month-title-spacer td { height: 2.5mm; line-height: 2.5mm; }', $calendarTemplate);
        $this->assertStringContainsString('.calendar-page--compact .month-card { border: 0; }', $calendarTemplate);
        $this->assertStringContainsString('height: 4mm; line-height: 4mm; padding: 0; vertical-align: middle; width: 14.2857%;', $calendarTemplate);
        $this->assertStringContainsString('.calendar-page--compact .month-card__day { height: {{ $calendar[\'cell_height_mm\'] }}mm; width: 14.2857%; }', $calendarTemplate);
        $this->assertStringContainsString('.calendar-page--compact .month-card__day-column { width: 14.2857%; }', $calendarTemplate);
        $this->assertStringContainsString('.calendar-page--compact .month-card__day--weekend,', $calendarTemplate);
        $this->assertStringContainsString('.calendar-page--dense .month-card__day--weekend { background: #ecefed; color: #8a918c; }', $calendarTemplate);
        $this->assertStringNotContainsString('month-card__week-row--empty', $calendarTemplate);
        $this->assertStringNotContainsString('$emptyPaddingRow', $calendarTemplate);
        $this->assertStringContainsString('$hasCalendarEvent = $day[\'is_start\'] || ! empty($day[\'comments\']);', $calendarTemplate);
        $this->assertStringContainsString('if ($calendar[\'layout\'] !== \'large\' && $day[\'in_month\'] && in_array($day[\'date\']->dayOfWeek, [5, 6], true) && ! $day[\'scheduled\'] && ! $hasCalendarEvent)', $calendarTemplate);
        $this->assertStringContainsString("if (\$day['in_month'] && \$day['scheduled'])", $calendarTemplate);
        $this->assertStringContainsString("if (\$day['in_month'] && \$day['is_start'])", $calendarTemplate);
        $this->assertStringNotContainsString("if (\$day['in_month'] && \$day['is_end'])", $calendarTemplate);
        $this->assertStringContainsString("@if(\$day['in_month'] && \$visibleMarkerLines !== [])", $calendarTemplate);
        $this->assertStringContainsString("'background-color: rgba(%d, %d, %d, 0.24); color: %s;'", $calendarTemplate);
        $this->assertStringContainsString("\$dayCellStyle = \$eventCellStyle(\$eventColor);", $calendarTemplate);
        $this->assertStringContainsString("\$dayCellStyle .= ' height: '.\$monthCellHeight.'mm;'", $calendarTemplate);
        $this->assertStringContainsString('hexdec(substr($color, 1, 2)) * 0.52', $calendarTemplate);
        $this->assertStringContainsString('font-size: 15px;', $calendarTemplate);
        $this->assertStringContainsString('.calendar-page--compact .month-card__marker { font-family: dubailight, dubai, sans-serif; font-size: 7px; font-weight: normal;', $calendarTemplate);
        $this->assertStringContainsString('.calendar-page--dense .month-card__marker { font-family: dubailight, dubai, sans-serif; font-size: 5.5px; font-weight: normal;', $calendarTemplate);
        $this->assertStringContainsString('.calendar-page--dense .months-layout { border-spacing: 4.8mm {{ $calendar[\'row_count\'] >= 6 ? 5.5 : 10.5 }}mm; }', $calendarTemplate);
        $this->assertStringContainsString('.calendar-page--dense .month-title { font-size: 11px; padding-bottom: 0; }', $calendarTemplate);
        $this->assertStringContainsString('<table class="month-title-spacer" aria-hidden="true"><tr><td>&nbsp;</td></tr></table>', $calendarTemplate);
        $this->assertStringContainsString("mb_strlen(trim(\$markerLine['text'])) > \$markerCharacterLimit", $calendarTemplate);
        $this->assertStringContainsString('.month-card__long-cell-table { border: 0; border-collapse: collapse; direction: ltr; table-layout: fixed; width: 100%; }', $calendarTemplate);
        $this->assertStringContainsString('.month-card .month-card__long-cell-number { padding-left: 0.6mm; text-align: center; vertical-align: middle; width: 60%; }', $calendarTemplate);
        $this->assertStringContainsString('font-size: 12px; font-weight: bold; line-height: 0.7; padding: 0 0.7mm 0 0; text-align: right; vertical-align: top; width: 20%;', $calendarTemplate);
        $this->assertStringContainsString('<table class="month-card__long-cell-table" dir="ltr" style="height: {{ max(1, $monthCellHeight - 0.5) }}mm;"><tr>', $calendarTemplate);
        $this->assertStringContainsString('<span class="month-card__marker" style="top: {{ $monthMarkerTop }}mm;">', $calendarTemplate);
        $this->assertStringContainsString('<td class="month-card__long-cell-side" width="20%">&nbsp;</td>', $calendarTemplate);
        $this->assertStringContainsString('<td class="month-card__long-cell-number" width="60%" align="center" valign="middle"><span class="month-card__number">', $calendarTemplate);
        $this->assertStringContainsString('@if($hasOverflowMarker) data-calendar-overflow-marker @endif', $calendarTemplate);
        $this->assertStringContainsString('<span data-calendar-overflow-marker>*</span>', $calendarTemplate);
        $this->assertStringNotContainsString('month-card__long-number-table', $calendarTemplate);
        $this->assertStringNotContainsString('month-card__long-number-cell', $calendarTemplate);
        $this->assertStringNotContainsString('month-card__overflow-marker-cell', $calendarTemplate);
        $this->assertStringNotContainsString('month-card__overflow-table', $calendarTemplate);
        $this->assertStringContainsString("style=\"color: {{ \$eventTextColor(\$markerLine['color']) }}\"", $calendarTemplate);
        $this->assertStringContainsString('$date->format(\'d-m-Y\')', $calendarTemplate);
        $this->assertStringNotContainsString('$digits($date->format(\'d-m-Y\'))', $calendarTemplate);
        $this->assertStringNotContainsString('.calendar-header { border-bottom:', $calendarTemplate);
    }

    public function test_calendar_uses_the_uploaded_artwork_as_a_full_bleed_background(): void
    {
        $backgroundPath = public_path('images/course-calendar-background.png');
        $controllerSource = file_get_contents(app_path('Http/Controllers/CourseCalendarPdfController.php'));

        $this->assertFileExists($backgroundPath);
        $this->assertSame([2382, 3368], array_slice(getimagesize($backgroundPath), 0, 2));
        $this->assertStringContainsString("public_path('images/course-calendar-background.png')", $controllerSource);
        $this->assertStringContainsString('SetWatermarkImage($calendarBackground, 1, [210, 297], [0, 0])', $controllerSource);
    }
}
