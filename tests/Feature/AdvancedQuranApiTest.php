<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\MemorizationSession;
use App\Models\ParentProfile;
use App\Models\QuranFinalTest;
use App\Models\QuranJuz;
use App\Models\QuranPartialTest;
use App\Models\Student;
use App\Models\StudentPageAchievement;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdvancedQuranApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_teacher_can_complete_partial_and_final_quran_cycles(): void
    {
        $context = $this->createContext();
        $this->grantJuzPages($context);
        Sanctum::actingAs($context['user']);

        $this->getJson('/api/v1/enrollments/'.$context['enrollment']->id.'/quran-partial-tests/eligible-juz')
            ->assertOk()->assertJsonPath('data.0.id', $context['juz']->id);
        $createdPartial = $this->postJson('/api/v1/enrollments/'.$context['enrollment']->id.'/quran-partial-tests', [
            'juz_id' => $context['juz']->id,
        ])->assertCreated()->assertJsonCount(4, 'data.parts');
        $partial = QuranPartialTest::query()->findOrFail($createdPartial->json('data.id'));

        $this->getJson('/api/v1/quran-partial-tests')->assertOk()->assertJsonPath('data.0.id', $partial->id);
        $this->getJson('/api/v1/quran-partial-tests/'.$partial->id)->assertOk()->assertJsonPath('data.status', 'in_progress');
        foreach ($partial->parts()->orderBy('part_number')->get() as $part) {
            $this->postJson('/api/v1/quran-partial-tests/'.$partial->id.'/parts/'.$part->id.'/attempts', [
                'mistake_count' => 0,
                'tested_on' => '2026-10-12',
            ])->assertCreated()->assertJsonPath('data.status', 'passed');
        }
        $this->assertSame('passed', $partial->fresh()->status);
        $this->assertSame('2026-10-12', $partial->fresh()->passed_on?->format('Y-m-d'));

        $this->getJson('/api/v1/enrollments/'.$context['enrollment']->id.'/quran-final-tests/eligible-juz')
            ->assertOk()->assertJsonPath('data.0.id', $context['juz']->id);
        $createdFinal = $this->postJson('/api/v1/enrollments/'.$context['enrollment']->id.'/quran-final-tests', [
            'juz_id' => $context['juz']->id,
        ])->assertCreated()->assertJsonPath('data.status', 'in_progress');
        $final = QuranFinalTest::query()->findOrFail($createdFinal->json('data.id'));
        $this->postJson('/api/v1/quran-final-tests/'.$final->id.'/attempts', [
            'score' => 100,
            'tested_on' => '2026-10-13',
        ])->assertCreated()->assertJsonPath('data.status', 'passed');

        $this->getJson('/api/v1/quran-final-tests')->assertOk()->assertJsonPath('data.0.id', $final->id);
        $this->getJson('/api/v1/quran-final-tests/'.$final->id)
            ->assertOk()->assertJsonPath('data.passed_on', '2026-10-13')->assertJsonCount(1, 'data.attempts');
    }

    public function test_teacher_cannot_access_another_groups_advanced_quran_records(): void
    {
        $context = $this->createContext();
        $other = $this->createContext('Other');
        $otherPartial = QuranPartialTest::query()->create(['created_by' => $other['user']->id, 'enrollment_id' => $other['enrollment']->id, 'juz_id' => $other['juz']->id, 'status' => 'in_progress', 'student_id' => $other['student']->id]);
        $otherPartial->parts()->createMany(collect(range(1, 4))->map(fn (int $part) => ['part_number' => $part, 'status' => 'pending'])->all());
        $otherFinal = QuranFinalTest::query()->create(['created_by' => $other['user']->id, 'enrollment_id' => $other['enrollment']->id, 'juz_id' => $other['juz']->id, 'status' => 'in_progress', 'student_id' => $other['student']->id]);
        Sanctum::actingAs($context['user']);

        $this->getJson('/api/v1/quran-partial-tests')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/quran-partial-tests/'.$otherPartial->id)->assertForbidden();
        $this->getJson('/api/v1/quran-final-tests/'.$otherFinal->id)->assertForbidden();
        $this->getJson('/api/v1/enrollments/'.$other['enrollment']->id.'/quran-final-tests/eligible-juz')->assertForbidden();
        $this->postJson('/api/v1/enrollments/'.$other['enrollment']->id.'/quran-partial-tests', ['juz_id' => $other['juz']->id])->assertForbidden();
    }

    public function test_advanced_quran_endpoints_require_permissions(): void
    {
        $context = $this->createContext();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/quran-partial-tests')->assertForbidden();
        $this->getJson('/api/v1/quran-final-tests')->assertForbidden();
        $this->postJson('/api/v1/enrollments/'.$context['enrollment']->id.'/quran-partial-tests', ['juz_id' => $context['juz']->id])->assertForbidden();
    }

    protected function createContext(string $suffix = ''): array
    {
        $year = AcademicYear::query()->where('is_current', true)->firstOrFail();
        $user = User::factory()->create();
        $user->assignRole('teacher');
        $teacher = Teacher::query()->create(['first_name' => 'Quran', 'is_helping' => true, 'last_name' => 'Teacher '.$suffix, 'phone' => '098'.random_int(1000000, 9999999), 'status' => 'active', 'user_id' => $user->id]);
        $course = Course::query()->create(['academic_year_id' => $year->id, 'is_active' => true, 'name' => 'Quran API Course '.$suffix]);
        $group = Group::query()->create(['academic_year_id' => $year->id, 'capacity' => 20, 'course_id' => $course->id, 'is_active' => true, 'name' => 'Quran API Group '.$suffix, 'teacher_id' => $teacher->id]);
        $parent = ParentProfile::query()->create(['father_name' => 'Quran Parent '.$suffix]);
        $student = Student::query()->create(['birth_date' => '2014-01-01', 'first_name' => 'Quran', 'last_name' => 'Student '.$suffix, 'parent_id' => $parent->id, 'status' => 'active']);
        $enrollment = Enrollment::query()->create(['enrolled_at' => '2026-09-01', 'group_id' => $group->id, 'status' => 'active', 'student_id' => $student->id]);
        $juz = QuranJuz::query()->where('juz_number', 1)->firstOrFail();

        return compact('enrollment', 'group', 'juz', 'student', 'teacher', 'user');
    }

    protected function grantJuzPages(array $context): void
    {
        $session = MemorizationSession::query()->create(['enrollment_id' => $context['enrollment']->id, 'entry_type' => 'new', 'from_page' => $context['juz']->from_page, 'pages_count' => $context['juz']->to_page - $context['juz']->from_page + 1, 'recorded_by_user_id' => $context['user']->id, 'recorded_on' => '2026-10-01', 'student_id' => $context['student']->id, 'teacher_id' => $context['teacher']->id, 'to_page' => $context['juz']->to_page]);
        foreach (range($context['juz']->from_page, $context['juz']->to_page) as $page) {
            StudentPageAchievement::query()->create(['first_enrollment_id' => $context['enrollment']->id, 'first_recorded_on' => '2026-10-01', 'first_session_id' => $session->id, 'page_no' => $page, 'student_id' => $context['student']->id]);
        }
    }
}
