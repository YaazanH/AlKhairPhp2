<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\MemorizationSession;
use App\Models\ParentProfile;
use App\Models\PointTransaction;
use App\Models\PointType;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\Landlord\CurrentModuleAccess;
use App\Services\MemorizationPointRepairService;
use App\Services\MemorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class MemorizationPointRepairTest extends TestCase
{
    use RefreshDatabase;

    private Teacher $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 1)->startOfDay());
        $this->seed();
        $this->teacher = Teacher::create(['first_name' => 'Reward', 'last_name' => 'Teacher', 'phone' => '0998111222', 'status' => 'active']);
    }

    public function test_deleting_last_session_voids_its_reward_and_preserves_other_point_sources(): void
    {
        $enrollment = $this->enrollment();
        $session = $this->recordSession($enrollment, 582);
        $reward = PointTransaction::where('source_type', 'memorization_session')->sole();
        foreach (['manual', 'student_attendance', 'quran_test'] as $source) {
            $this->points($enrollment, $source, 987, 7);
        }
        $otherRows = PointTransaction::where('source_type', '!=', 'memorization_session')->get()->toArray();

        app(MemorizationService::class)->deleteSession($session);

        $this->assertNotNull($reward->fresh()->voided_at);
        $this->assertSame(21, $enrollment->fresh()->final_points_cached);
        $this->assertSame(0, $enrollment->fresh()->memorized_pages_cached);
        $this->assertDatabaseCount('memorization_sessions', 0);
        $this->assertDatabaseCount('student_page_achievements', 0);
        $this->assertSame($otherRows, PointTransaction::where('source_type', '!=', 'memorization_session')->get()->toArray());
    }

    public function test_deleting_either_same_day_entry_replaces_daily_reward_without_leaving_an_orphan(): void
    {
        foreach ([true, false] as $deleteLatest) {
            $enrollment = $this->enrollment();
            $first = $this->recordSession($enrollment, 582);
            $second = $this->recordSession($enrollment, 583);
            $this->assertSame(25, $enrollment->fresh()->final_points_cached);
            app(MemorizationService::class)->deleteSession($deleteLatest ? $second : $first);
            $this->assertSame(10, $enrollment->fresh()->final_points_cached);
            $this->assertSame(1, PointTransaction::where('enrollment_id', $enrollment->id)->notVoided()->count());
            app(MemorizationService::class)->deleteSession($deleteLatest ? $first : $second);
            $this->assertSame(0, $enrollment->fresh()->final_points_cached);
        }
    }

    public function test_deleting_old_or_review_entries_does_not_award_points(): void
    {
        $enrollment = $this->enrollment();
        foreach (['old', 'review'] as $type) {
            $session = $this->recordSession($enrollment, 582, $type);
            app(MemorizationService::class)->deleteSession($session);
        }
        $this->assertDatabaseCount('point_transactions', 0);
        $this->assertSame(0, $enrollment->fresh()->final_points_cached);
    }

    public function test_ended_course_points_and_caches_are_untouched_by_repair_and_future_deletes(): void
    {
        foreach (['finished', 'inactive', 'past_end', 'finished_group', 'finished_enrollment'] as $state) {
            $enrollment = $this->enrollment();
            $session = $this->recordSession($enrollment, 582);
            $this->points($enrollment, 'memorization_session', 900000 + $enrollment->id, 43);
            $this->protect($enrollment, $state);
            $beforePoints = PointTransaction::where('enrollment_id', $enrollment->id)->get()->toArray();
            $beforeEnrollment = $enrollment->fresh()->toArray();
            try {
                app(MemorizationService::class)->deleteSession($session);
                $this->fail('Protected course allowed deletion: '.$state);
            } catch (LogicException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
            $report = app(MemorizationPointRepairService::class)->run(true, today()->toDateString(), 'protected');
            $this->assertSame(0, $report['candidate_count']);
            $this->assertSame($beforePoints, PointTransaction::where('enrollment_id', $enrollment->id)->get()->toArray(), $state);
            $this->assertSame($beforeEnrollment, $enrollment->fresh()->toArray(), $state);
            $this->assertNotNull($session->fresh());
        }
    }

    public function test_active_course_delete_does_not_refresh_another_ended_enrollment_for_the_student(): void
    {
        $ended = $this->enrollment();
        $this->recordSession($ended, 582);
        $this->protect($ended, 'finished');
        Enrollment::whereKey($ended->id)->update(['final_points_cached' => 123, 'memorized_pages_cached' => 456]);
        $before = $ended->fresh()->toArray();
        $points = PointTransaction::where('enrollment_id', $ended->id)->get()->toArray();
        $active = $this->enrollment($ended->student);
        app(MemorizationService::class)->deleteSession($this->recordSession($active, 583));
        $this->assertSame($before, $ended->fresh()->toArray());
        $this->assertSame($points, PointTransaction::where('enrollment_id', $ended->id)->get()->toArray());
    }

    public function test_repair_previews_then_voids_only_confirmed_orphans_without_repricing_or_rebuilding_progress(): void
    {
        $enrollment = $this->enrollment();
        $this->recordSession($enrollment, 582);
        $orphan = $this->points($enrollment, 'memorization_session', 900001, 37);
        $this->points($enrollment, 'manual', null, 7);
        $this->points($enrollment, 'quran_test', 900001, 8);
        Enrollment::whereKey($enrollment->id)->update(['final_points_cached' => 62, 'memorized_pages_cached' => 19]);
        $beforePoints = PointTransaction::all()->toArray();
        $beforeEnrollment = $enrollment->fresh()->toArray();
        $beforeStudent = $enrollment->student->fresh()->toArray();
        $beforeAchievements = DB::table('student_page_achievements')->get()->toArray();
        $repair = app(MemorizationPointRepairService::class);

        $preview = $repair->run(false, today()->toDateString(), 'preview');
        $this->assertSame(1, $preview['candidate_count']);
        $this->assertSame(62, $preview['enrollment_balances'][0]['before']);
        $this->assertSame(25, $preview['enrollment_balances'][0]['after']);
        $this->assertSame($beforePoints, PointTransaction::all()->toArray());
        $this->assertSame($beforeEnrollment, $enrollment->fresh()->toArray());

        $applied = $repair->run(true, today()->toDateString(), 'apply-batch');
        $this->assertSame(1, $applied['candidate_count']);
        $this->assertNotNull($orphan->fresh()->voided_at);
        $this->assertStringContainsString('apply-batch', $orphan->fresh()->void_reason);
        $this->assertSame(25, $enrollment->fresh()->final_points_cached);
        $this->assertSame(19, $enrollment->fresh()->memorized_pages_cached);
        $this->assertSame($beforeStudent, $enrollment->student->fresh()->toArray());
        $this->assertEquals($beforeAchievements, DB::table('student_page_achievements')->get()->toArray());
        $this->assertSame(array_values(array_filter($beforePoints, fn ($row) => $row['id'] !== $orphan->id)),
            PointTransaction::whereKeyNot($orphan->id)->get()->toArray());
        $after = PointTransaction::all()->toArray();
        $this->assertSame(0, $repair->run(true, today()->toDateString(), 'repeat')['candidate_count']);
        $this->assertSame($after, PointTransaction::all()->toArray());
    }

    public function test_ambiguous_source_and_ownership_are_reported_without_changing_any_rows(): void
    {
        $enrollment = $this->enrollment();
        $source = $this->recordSession($enrollment, 582);
        $other = $this->enrollment();
        $this->points($other, 'memorization_session', $source->id, 40);
        $this->points($other, 'memorization_session', null, 30);
        $badOwnership = $this->points($other, 'memorization_session', 999991, 20);
        $badOwnership->update(['student_id' => $enrollment->student_id]);
        $missingEnrollment = $this->points($other, 'memorization_session', 999992, 10);
        $missingEnrollment->update(['enrollment_id' => null]);
        $before = PointTransaction::all()->toArray();

        $result = app(MemorizationPointRepairService::class)->run(true, today()->toDateString(), 'ambiguous');

        $this->assertSame(0, $result['candidate_count']);
        $this->assertSame(4, $result['skipped_count']);
        $this->assertSame($before, PointTransaction::all()->toArray());
    }

    public function test_repair_rechecks_course_state_after_preview(): void
    {
        $enrollment = $this->enrollment();
        $orphan = $this->points($enrollment, 'memorization_session', 999991, 55);
        $repair = app(MemorizationPointRepairService::class);
        $this->assertSame(1, $repair->run(false, today()->toDateString(), 'preview')['candidate_count']);
        $this->protect($enrollment, 'past_end');
        $before = $enrollment->fresh()->toArray();
        $result = $repair->run(true, today()->toDateString(), 'apply');
        $this->assertSame(0, $result['candidate_count']);
        $this->assertNull($orphan->fresh()->voided_at);
        $this->assertSame($before, $enrollment->fresh()->toArray());
    }

    public function test_repair_aggregates_multiple_orphans_and_does_not_require_points_module_access(): void
    {
        $enrollment = $this->enrollment();
        $this->points($enrollment, 'memorization_session', 999991, 17);
        $this->points($enrollment, 'memorization_session', 999992, 23);
        $this->points($enrollment, 'manual', null, 11);
        $this->mock(CurrentModuleAccess::class)->shouldReceive('enabled')->andReturn(false);
        $repair = app(MemorizationPointRepairService::class);
        $preview = $repair->run(false, today()->toDateString(), 'preview');
        $this->assertSame(2, $preview['candidate_count']);
        $this->assertSame(51, $preview['enrollment_balances'][0]['before']);
        $this->assertSame(11, $preview['enrollment_balances'][0]['after']);
        $result = $repair->run(true, today()->toDateString(), 'apply');
        $this->assertSame($preview['enrollment_balances'], $result['enrollment_balances']);
        $this->assertSame(11, $enrollment->fresh()->final_points_cached);
    }

    public function test_deletion_still_voids_the_reward_when_points_module_is_disabled(): void
    {
        $enrollment = $this->enrollment();
        $session = $this->recordSession($enrollment, 582);
        $this->mock(CurrentModuleAccess::class)->shouldReceive('enabled')->andReturn(false);
        app(MemorizationService::class)->deleteSession($session);
        $this->assertSame(0, $enrollment->fresh()->final_points_cached);
        $this->assertSame(0, PointTransaction::notVoided()->count());
    }

    private function enrollment(?Student $student = null): Enrollment
    {
        $student ??= Student::create(['parent_id' => ParentProfile::create(['father_name' => 'Parent'])->id,
            'first_name' => 'Reward', 'last_name' => 'Student', 'birth_date' => '2014-05-12', 'status' => 'active']);
        $course = Course::create(['name' => 'Reward Course '.Course::count(), 'is_active' => true]);
        $group = Group::create(['course_id' => $course->id, 'academic_year_id' => AcademicYear::where('is_current', true)->value('id'),
            'teacher_id' => $this->teacher->id, 'name' => 'Reward Group', 'capacity' => 12, 'is_active' => true]);

        return Enrollment::create(['student_id' => $student->id, 'group_id' => $group->id, 'enrolled_at' => '2026-09-01', 'status' => 'active']);
    }

    private function recordSession(Enrollment $enrollment, int $page, string $type = 'new'): MemorizationSession
    {
        return app(MemorizationService::class)->saveSession($enrollment, ['teacher_id' => $this->teacher->id,
            'recorded_on' => '2026-09-25', 'entry_type' => $type, 'from_page' => $page, 'to_page' => $page]);
    }

    private function points(Enrollment $enrollment, string $source, ?int $sourceId, int $points): PointTransaction
    {
        return PointTransaction::create(['student_id' => $enrollment->student_id, 'enrollment_id' => $enrollment->id,
            'point_type_id' => PointType::firstOrFail()->id, 'source_type' => $source, 'source_id' => $sourceId,
            'points' => $points, 'entered_at' => now()]);
    }

    private function protect(Enrollment $enrollment, string $state): void
    {
        // Query updates intentionally preserve historical snapshots instead of triggering course backfills.
        match ($state) {
            'finished' => Course::whereKey($enrollment->group->course_id)->update(['finished_at' => now()]),
            'inactive' => Course::whereKey($enrollment->group->course_id)->update(['is_active' => false]),
            'past_end' => Course::whereKey($enrollment->group->course_id)->update(['ends_on' => today()->subDay()]),
            'finished_group' => Group::whereKey($enrollment->group_id)->update(['course_finished_at' => now()]),
            'finished_enrollment' => Enrollment::whereKey($enrollment->id)->update(['course_finished_at' => now()]),
        };
    }
}
