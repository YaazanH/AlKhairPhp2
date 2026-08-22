<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\QueriesStudentRecords;
use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use App\Models\Student;
use App\Services\AccessScopeService;
use App\Services\MobileStudentProgressService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Role-aware per-student reads for the mobile app.
 *
 * The existing `parent/children/{student}/*` endpoints require an active parent
 * profile, so student, teacher, circle-supervisor, assistant-supervisor and
 * admin accounts get a 403 from them. These endpoints serve the same payload
 * shapes to any role, with visibility decided by AccessScopeService — the same
 * service the dashboard uses, so nobody sees a record they could not already
 * see on the web.
 *
 * Read-only and non-financial by design: no invoices, payments or activity fees.
 */
class StudentRecordsController extends Controller
{
    use QueriesStudentRecords;

    public function show(Request $request, Student $student): JsonResponse
    {
        $this->authorizeStudent($request, $student, 'students.view');

        $student->load([
            'gradeLevel',
            'quranCurrentJuz',
            'parentProfile',
            'enrollments.group.course',
            'enrollments.group.teacher',
            'enrollments.group.assistantTeacher',
        ]);

        $progress = app(MobileStudentProgressService::class)
            ->snapshot($student, $request->user());

        return response()->json([
            'data' => $this->studentDetail($student) + [
                'parent' => $this->parentContact($student),
                'contacts' => $this->contactChannels($student),
                'school_name' => $student->school_name,
                'address' => $student->parentProfile?->address,
                'notes' => $student->notes,
                // Every enrollment, not just the active ones, so a supervisor
                // can see the student's history across circles.
                'enrollments' => $student->enrollments
                    ->map(fn (Enrollment $enrollment): array => $this->enrollmentSummary($enrollment) + [
                        'assistant_teacher' => $this->teacherSummary($enrollment->group?->assistantTeacher),
                    ])
                    ->values(),
                'stats' => $progress['stats'],
                'quran_totals' => $progress['totals'],
            ],
        ]);
    }

    /**
     * Per-juz Quran progress: which pages were recited, which are still
     * missing, and the test state for each juz — the same view the dashboard's
     * student progress page renders.
     */
    public function quranProgress(Request $request, Student $student): JsonResponse
    {
        $this->authorizeStudent($request, $student, 'memorization.view');

        $progress = app(MobileStudentProgressService::class)
            ->snapshot($student, $request->user());

        return response()->json([
            'data' => [
                'stats' => $progress['stats'],
                'totals' => $progress['totals'],
                'juz' => $progress['juz'],
            ],
        ]);
    }

    public function attendance(Request $request, Student $student): JsonResponse
    {
        $own = $this->authorizeStudent($request, $student, 'attendance.student.view');
        $filters = $this->dateFilters($request);

        return response()->json($this->studentAttendancePayload(
            $student,
            $filters,
            $own ? null : $this->scope($request, 'scopeStudentAttendanceRecords'),
        ));
    }

    public function memorization(Request $request, Student $student): JsonResponse
    {
        $own = $this->authorizeStudent($request, $student, 'memorization.view');
        $filters = $this->dateFilters($request, [
            'entry_type' => ['nullable', Rule::in(['new', 'review', 'correction'])],
        ]);

        return response()->json($this->studentMemorizationPayload(
            $student,
            $filters,
            $own ? null : $this->scope($request, 'scopeMemorizationSessions'),
        ));
    }

    public function points(Request $request, Student $student): JsonResponse
    {
        $own = $this->authorizeStudent($request, $student, 'points.view');
        $filters = $this->dateFilters($request);

        return response()->json($this->studentPointsPayload(
            $student,
            $filters,
            $own ? null : $this->scope($request, 'scopePointTransactions'),
        ));
    }

    public function assessments(Request $request, Student $student): JsonResponse
    {
        $own = $this->authorizeStudent($request, $student, 'assessment-results.view');
        $filters = $this->dateFilters($request);

        return response()->json($this->studentAssessmentsPayload(
            $student,
            $filters,
            $own ? null : $this->scope($request, 'scopeAssessmentResults'),
        ));
    }

    public function quranTests(Request $request, Student $student): JsonResponse
    {
        $this->authorizeStudent($request, $student, 'quran-tests.view');
        $filters = $this->dateFilters($request);

        return response()->json($this->studentQuranTestsPayload($student, $filters));
    }

    public function notes(Request $request, Student $student): JsonResponse
    {
        $own = $this->authorizeStudent($request, $student, 'student-notes.view');
        $filters = $this->dateFilters($request);

        // Own record (student themselves, or a parent's child) sees exactly what
        // the parent API exposes. Staff get the dashboard's own visibility rules.
        return response()->json($own
            ? $this->studentNotesPayload($student, $filters, visibility: 'visible_to_parent')
            : $this->studentNotesPayload($student, $filters, $this->scope($request, 'scopeStudentNotes')));
    }

    /**
     * Confirm the caller may read this student, and report whether it is their
     * own record — their linked student profile, or one of their children.
     *
     * Own records are granted on the relationship alone, matching how the
     * existing parent endpoints already work. Everyone else needs both the
     * named permission and an AccessScopeService grant.
     */
    protected function authorizeStudent(Request $request, Student $student, string $permission): bool
    {
        $user = $request->user();

        abort_unless($user, 401);

        if ($this->isOwnStudent($request, $student)) {
            return true;
        }

        abort_unless($user->can($permission), 403);
        abort_unless(app(AccessScopeService::class)->canAccessStudent($user, $student), 404);

        return false;
    }

    protected function isOwnStudent(Request $request, Student $student): bool
    {
        $user = $request->user();

        if ($user?->studentProfile?->id && (int) $user->studentProfile->id === (int) $student->id) {
            return true;
        }

        $parentId = $user?->parentProfile?->id;

        return $parentId !== null && (int) $student->parent_id === (int) $parentId;
    }

    /**
     * Wrap an AccessScopeService scope method as a query modifier.
     *
     * @return callable(Builder): Builder
     */
    protected function scope(Request $request, string $method): callable
    {
        $scopes = app(AccessScopeService::class);
        $user = $request->user();

        return fn (Builder $query): Builder => $scopes->{$method}($query, $user);
    }

    /**
     * Contact block the mobile app renders on a student profile. Matches the
     * `parent` object the app's StudentParentInfo model already parses.
     */
    protected function parentContact(Student $student): ?array
    {
        $parent = $student->parentProfile;

        if (! $parent) {
            return null;
        }

        return [
            'id' => $parent->id,
            'parent_number' => $parent->parent_number,
            'father_name' => $parent->father_name,
            'father_work' => $parent->father_work,
            'mother_name' => $parent->mother_name,
            'father_phone' => $parent->father_phone,
            'mother_phone' => $parent->mother_phone,
            'home_phone' => $parent->home_phone,
            'address' => $parent->address,
            'notes' => $parent->notes,
            'is_active' => (bool) $parent->is_active,
        ];
    }

    /**
     * Dialable numbers with a label, so the app can offer call / WhatsApp
     * actions without the screen having to know which fields exist.
     *
     * @return array<int, array{label: string, phone: string}>
     */
    protected function contactChannels(Student $student): array
    {
        $parent = $student->parentProfile;

        $candidates = [
            ['label' => 'الأب', 'phone' => $parent?->father_phone],
            ['label' => 'الأم', 'phone' => $parent?->mother_phone],
            ['label' => 'المنزل', 'phone' => $parent?->home_phone],
        ];

        return array_values(array_filter(
            $candidates,
            fn (array $contact): bool => filled($contact['phone']),
        ));
    }
}
