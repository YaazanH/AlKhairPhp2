<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Teacher;
use App\Models\TeacherAttendanceDay;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneCourselessAttendanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_cleanup_removes_only_course_less_days_and_their_records(): void
    {
        $course = Course::create(['name' => 'Keep attendance', 'is_active' => true]);
        $teacher = Teacher::create(['first_name' => 'Cleanup', 'last_name' => 'Teacher', 'phone' => '0999000123', 'status' => 'active']);
        $keep = TeacherAttendanceDay::create([
            'attendance_date' => '2026-10-22', 'course_id' => $course->id, 'status' => 'closed',
        ]);
        $keepRecord = $keep->records()->create(['teacher_id' => $teacher->id]);
        foreach (['open', 'closed'] as $status) {
            $day = TeacherAttendanceDay::create([
                'attendance_date' => $status === 'open' ? '2026-10-22' : '2026-10-23',
                'status' => $status,
            ]);
            $day->records()->create(['teacher_id' => $teacher->id]);
        }

        $this->artisan('attendance:prune-courseless')
            ->expectsOutput('Deleted 2 course-less attendance day(s).')->assertSuccessful();
        $this->assertDatabaseCount('teacher_attendance_days', 1);
        $this->assertDatabaseCount('teacher_attendance_records', 1);
        $this->assertDatabaseHas('teacher_attendance_days', ['id' => $keep->id]);
        $this->assertDatabaseHas('teacher_attendance_records', ['id' => $keepRecord->id]);
        $this->artisan('attendance:prune-courseless')
            ->expectsOutput('Deleted 0 course-less attendance day(s).')->assertSuccessful();
    }

    public function test_cleanup_is_scheduled_every_minute_without_overlapping(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains($event->command ?? '', 'attendance:prune-courseless'));
        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
