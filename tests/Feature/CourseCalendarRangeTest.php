<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\CourseCalendarEntry;
use App\Models\User;
use App\Services\CourseCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class CourseCalendarRangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_course_date_ranges_remain_day_first_and_ltr_in_both_languages(): void
    {
        $course = Course::create(['name' => 'Date display', 'starts_on' => '2026-09-17', 'ends_on' => '2027-05-06', 'is_active' => true]);
        $this->editor($course);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $html = Volt::test('courses.index')->html();
            $separator = $locale === 'ar' ? 'إلى' : 'to';
            $this->assertMatchesRegularExpression('/<span dir="ltr"[^>]*>17-09-2026<\/span> '.$separator.' <span dir="ltr"[^>]*>06-05-2027<\/span>/', $html);
        }

        $this->assertSame('2026-09-17', $course->fresh()->starts_on->toDateString());
        $this->assertSame('2027-05-06', $course->fresh()->ends_on->toDateString());
    }

    private function editor(Course $course)
    {
        $this->seed();
        if (! $course->academic_year_id) {
            $course->update(['academic_year_id' => AcademicYear::query()->where('is_active', true)->value('id')]);
        }
        $user = User::factory()->create();
        $user->assignRole('super_admin');
        $this->actingAs($user);

        return Volt::test('courses.index')->call('openCourseCalendar', $course->id);
    }

    public function test_range_is_saved_as_one_entry_and_can_be_edited_or_changed_to_one_day(): void
    {
        $course = Course::create(['name' => 'Calendar range', 'starts_on' => '2026-09-01', 'ends_on' => '2027-04-30', 'is_active' => true]);
        $editor = $this->editor($course)->assertViewHas('calendarColors', fn ($colors) => count($colors) === 30)
            ->set('calendarDate', '2026-09-28')->set('calendarEndDate', '2026-10-03')
            ->set('calendarName', 'Autumn break')->set('calendarColor', '#C4E8DF')
            ->call('saveCourseCalendar')->assertHasNoErrors();
        $entry = $course->calendarEntries()->sole();
        $this->assertSame('2026-10-03', $entry->end_date->toDateString());
        $this->assertSame('#c4e8df', $entry->color);
        $editor->call('openCourseCalendar', $course->id)->call('editCalendarRow', 0)
            ->assertSet('calendarEndDate', '2026-10-03')->set('calendarEndDate', '2026-10-04')
            ->call('saveCourseCalendar')->assertHasNoErrors();
        $this->assertSame('2026-10-04', $entry->fresh()->end_date->toDateString());
        $editor->call('openCourseCalendar', $course->id)->call('editCalendarRow', 0)
            ->set('calendarEndDate', '')->call('saveCourseCalendar')->assertHasNoErrors();
        $this->assertNull($entry->fresh()->end_date);
        $this->assertSame(1, $course->calendarEntries()->count());
    }

    public function test_ranges_reject_reversed_outside_and_duplicate_intervals_including_tampered_rows(): void
    {
        $course = Course::create(['name' => 'Validated range', 'starts_on' => '2026-09-01', 'ends_on' => '2026-12-31', 'is_active' => true]);
        $editor = $this->editor($course)->set('calendarDate', '2026-10-10')->set('calendarName', 'Break');
        foreach (['2026-10-09', '2027-01-01'] as $end) {
            $editor->set('calendarEndDate', $end)->call('saveCalendarRow')->assertHasErrors('calendarEndDate');
        }
        $editor->set('calendarEndDate', '2026-10-15')->call('saveCalendarRow')->assertHasNoErrors()
            ->set('calendarDate', '2026-10-15')->set('calendarEndDate', '2026-10-17')->set('calendarName', 'Break')
            ->call('saveCalendarRow')->assertHasErrors('calendarName');
        $editor->call('editCalendarRow', 0)->call('saveCalendarRow')->assertHasNoErrors()
            ->set('calendarRows.0.end_date', '2026-10-09')->call('saveCourseCalendar')->assertHasErrors('calendarRows.0.end_date');
        $this->assertSame(0, $course->calendarEntries()->count());
    }

    public function test_range_colours_every_day_including_weekends_and_both_months_and_keeps_overlapping_events(): void
    {
        $course = new Course(['name' => 'Calendar', 'starts_on' => '2026-09-01', 'ends_on' => '2027-04-30']);
        $course->setRelation('schedules', collect());
        $course->setRelation('calendarEntries', collect([
            new CourseCalendarEntry(['date' => '2026-09-28', 'end_date' => '2026-10-04', 'name' => 'A long event name that must always remain fully readable', 'color' => '#c4e8df']),
            new CourseCalendarEntry(['date' => '2026-10-01', 'name' => 'لقاء', 'color' => '#ecd9af']),
        ]));
        $calendar = app(CourseCalendarService::class)->build($course);
        $days = collect($calendar['pages'][0])->pluck('weeks')->flatten(1)->pluck('days')->flatten(1)->where('in_month', true)->keyBy(fn ($day) => $day['date']->toDateString());
        foreach (['2026-09-28', '2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04'] as $date) {
            $this->assertSame('#c4e8df', $days[$date]['comments'][0]['color']);
        }
        $this->assertSame([], $days['2026-09-27']['comments']);
        $this->assertSame([], $days['2026-10-05']['comments']);
        $this->assertSame(['#c4e8df', '#ecd9af'], array_column($days['2026-10-01']['comments'], 'color'));
        $this->assertCount(3, $calendar['legend']);
        $html = view('reports.course-calendar', ['course' => $course, 'calendar' => $calendar, 'logo' => null])->render();
        $this->assertStringNotContainsString('A long event name that must always remain fully readable', $html);
        $this->assertStringContainsString('لقاء', $html);
        $this->assertStringNotContainsString('data-calendar-overflow-marker', $html);
    }

    public function test_short_calendar_labels_a_range_only_on_its_first_day_and_keeps_all_days_coloured(): void
    {
        $course = new Course(['name' => 'Short calendar', 'starts_on' => '2026-09-01', 'ends_on' => '2026-10-31']);
        $course->setRelation('schedules', collect());
        $course->setRelation('calendarEntries', collect([
            new CourseCalendarEntry(['date' => '2026-09-28', 'end_date' => '2026-10-04', 'name' => 'Range event', 'color' => '#c4e8df']),
            new CourseCalendarEntry(['date' => '2026-10-01', 'name' => 'Meeting', 'color' => '#ecd9af']),
        ]));
        $calendar = app(CourseCalendarService::class)->build($course);
        $this->assertSame('large', $calendar['layout']);
        $days = collect($calendar['pages'][0])->pluck('weeks')->flatten(1)->pluck('days')->flatten(1)->where('in_month', true)->keyBy(fn ($day) => $day['date']->toDateString());
        foreach (['2026-09-28', '2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04'] as $date) {
            $this->assertSame('#c4e8df', $days[$date]['comments'][0]['color']);
            $this->assertSame($date === '2026-09-28', $days[$date]['comments'][0]['is_start']);
        }
        $this->assertTrue($days['2026-10-01']['comments'][1]['is_start']);
        $html = view('reports.course-calendar', ['course' => $course, 'calendar' => $calendar, 'logo' => null])->render();
        $this->assertSame(1, substr_count($html, 'Range event'));
        $this->assertSame(1, substr_count($html, 'Meeting'));
    }

    public function test_legacy_duplicate_colours_are_distinct_without_an_extra_legend_page(): void
    {
        $course = new Course(['name' => 'Busy calendar', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $course->setRelation('schedules', collect());
        $course->setRelation('calendarEntries', collect(range(1, 30))->map(fn ($day) => new CourseCalendarEntry([
            'date' => '2026-02-'.str_pad((string) min($day, 28), 2, '0', STR_PAD_LEFT),
            'name' => 'Event '.$day.' with a complete descriptive name', 'color' => '#c4e8df',
        ])));
        $calendar = app(CourseCalendarService::class)->build($course);
        $this->assertCount(31, $calendar['legend']);
        $this->assertCount(31, array_unique(array_column($calendar['legend'], 'color')));
        $html = view('reports.course-calendar', ['course' => $course, 'calendar' => $calendar, 'logo' => null])->render();
        foreach (range(1, 30) as $number) {
            $this->assertStringNotContainsString('Event '.$number.' with a complete descriptive name', $html);
        }
    }

    public function test_colours_cannot_be_reused_in_rows_or_tampered_payloads_but_can_be_kept_when_editing(): void
    {
        $course = Course::create(['name' => 'Unique colours', 'starts_on' => '2026-09-01', 'ends_on' => '2026-12-31', 'is_active' => true]);
        $editor = $this->editor($course)->set('calendarDate', '2026-10-10')->set('calendarName', 'First')
            ->set('calendarColor', '#c4e8df')->call('saveCalendarRow')->assertHasNoErrors();
        $this->assertNotSame('#c4e8df', $editor->get('calendarColor'));
        $editor->set('calendarDate', '2026-10-11')->set('calendarName', 'Second')->set('calendarColor', '#C4E8DF')
            ->call('saveCalendarRow')->assertHasErrors('calendarColor')
            ->set('calendarColor', '#ecd9af')->call('saveCalendarRow')->assertHasNoErrors()
            ->call('editCalendarRow', 0)->call('saveCalendarRow')->assertHasNoErrors()
            ->set('calendarRows.1.color', '#c4e8df')->call('saveCourseCalendar')->assertHasErrors('calendarRows');
        $this->assertSame(0, $course->calendarEntries()->count());
        $editor->call('deleteCalendarRow', 0)->call('editCalendarRow', 0)->set('calendarColor', '#c4e8df')
            ->call('saveCourseCalendar')->assertHasNoErrors();
        $this->assertSame('#c4e8df', $course->calendarEntries()->sole()->color);
    }

    public function test_editor_and_pdf_use_the_same_unique_colours_for_legacy_entries(): void
    {
        $course = Course::create(['name' => 'Legacy colours', 'starts_on' => '2026-09-01', 'ends_on' => '2027-04-30', 'is_active' => true]);
        foreach (['Z event', 'A event', 'B event'] as $name) {
            $course->calendarEntries()->create(['date' => '2026-10-10', 'name' => $name, 'color' => '#399c91']);
        }
        $editor = $this->editor($course);
        $colours = collect($editor->get('calendarRows'))->pluck('color', 'name')->all();
        $this->assertCount(3, array_unique($colours));
        $legend = app(CourseCalendarService::class)->build($course->fresh())['legend'];
        foreach ($legend as $event) {
            if (isset($colours[$event['name']])) {
                $this->assertSame($colours[$event['name']], $event['color']);
            }
        }
        $editor->call('saveCourseCalendar')->assertHasNoErrors();
        $this->assertCount(3, $course->calendarEntries()->pluck('color')->unique());
    }
}
