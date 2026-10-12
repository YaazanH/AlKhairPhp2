<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use App\Models\QuranFinalTest;
use App\Models\QuranJuz;
use App\Models\QuranPartialTest;
use App\Models\QuranPartialTestPart;
use App\Models\Teacher;
use App\Services\AccessScopeService;
use App\Services\QuranFinalTestService;
use App\Services\QuranPartialTestService;
use App\Support\OperationalFeatureSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;

class AdvancedQuranController extends Controller
{
    public function partialTests(Request $request)
    {
        $this->authorizePermission($request, 'quran-partial-tests.view');
        $validated = $this->validatedList($request);

        return app(AccessScopeService::class)->scopeQuranPartialTests(QuranPartialTest::query(), $request->user())
            ->with(['enrollment.group.course', 'juz', 'parts.attempts', 'student'])
            ->when($validated['student_id'] ?? null, fn (Builder $query, int $id) => $query->where('student_id', $id))
            ->when($validated['group_id'] ?? null, fn (Builder $query, int $id) => $query->whereHas('enrollment', fn (Builder $builder) => $builder->where('group_id', $id)))
            ->when($validated['juz_id'] ?? null, fn (Builder $query, int $id) => $query->where('juz_id', $id))
            ->when($validated['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->latest('id')->paginate($validated['per_page'] ?? 15)
            ->through(fn (QuranPartialTest $test) => $this->partialPayload($test));
    }

    public function partialTest(Request $request, QuranPartialTest $partialTest)
    {
        $this->authorizePermission($request, 'quran-partial-tests.view');
        $this->authorizePartialTest($request, $partialTest);

        return response()->json(['data' => $this->partialPayload($partialTest->load(['enrollment.group.course', 'juz', 'parts.attempts.teacher', 'student.parentProfile']))]);
    }

    public function partialEligibleJuz(Request $request, Enrollment $enrollment)
    {
        $this->authorizePermission($request, 'quran-partial-tests.record');
        $this->authorizeEnrollment($request, $enrollment);
        $ids = app(QuranPartialTestService::class)->eligibleJuzIdsForStudent($enrollment->student);

        return response()->json(['data' => QuranJuz::query()->whereIn('id', $ids)->orderBy('juz_number')->get()->map(fn (QuranJuz $juz) => $this->juzPayload($juz))->values()]);
    }

    public function storePartialTest(Request $request, Enrollment $enrollment)
    {
        $this->authorizePermission($request, 'quran-partial-tests.record');
        $this->authorizeEnrollment($request, $enrollment);
        OperationalFeatureSettings::ensureMemorizationAndSabersEnabled();
        $validated = $request->validate(['allow_parallel_cycle' => ['nullable', 'boolean'], 'juz_id' => ['required', 'integer', 'exists:quran_juzs,id']]);

        try {
            $test = app(QuranPartialTestService::class)->create($enrollment->load('student.pageAchievements'), QuranJuz::query()->findOrFail($validated['juz_id']), (bool) ($validated['allow_parallel_cycle'] ?? false));
        } catch (LogicException $exception) {
            throw ValidationException::withMessages(['juz_id' => $exception->getMessage()]);
        }

        return response()->json(['data' => $this->partialPayload($test)], 201);
    }

    public function storePartialAttempt(Request $request, QuranPartialTest $partialTest, QuranPartialTestPart $part)
    {
        $this->authorizePermission($request, 'quran-partial-tests.record');
        $this->authorizePartialTest($request, $partialTest);
        abort_unless($part->quran_partial_test_id === $partialTest->id, 404);
        OperationalFeatureSettings::ensureMemorizationAndSabersEnabled();
        $validated = $request->validate(['mistake_count' => ['required', 'integer', 'min:0', 'max:999'], 'notes' => ['nullable', 'string'], 'teacher_id' => ['nullable', 'integer', 'exists:teachers,id'], 'tested_on' => ['required', 'date']]);

        try {
            $attempt = app(QuranPartialTestService::class)->recordAttempt($part, $this->recordingTeacher($request, $validated, 'quran-partial-tests.record'), $validated);
        } catch (LogicException $exception) {
            throw ValidationException::withMessages(['attempt' => $exception->getMessage()]);
        }

        return response()->json(['data' => $this->partialAttemptPayload($attempt)], 201);
    }

    public function finalTests(Request $request)
    {
        $this->authorizePermission($request, 'quran-final-tests.view');
        $validated = $this->validatedList($request);

        return app(AccessScopeService::class)->scopeQuranFinalTests(QuranFinalTest::query(), $request->user())
            ->with(['attempts.teacher', 'enrollment.group.course', 'juz', 'student'])
            ->when($validated['student_id'] ?? null, fn (Builder $query, int $id) => $query->where('student_id', $id))
            ->when($validated['group_id'] ?? null, fn (Builder $query, int $id) => $query->whereHas('enrollment', fn (Builder $builder) => $builder->where('group_id', $id)))
            ->when($validated['juz_id'] ?? null, fn (Builder $query, int $id) => $query->where('juz_id', $id))
            ->when($validated['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->latest('id')->paginate($validated['per_page'] ?? 15)
            ->through(fn (QuranFinalTest $test) => $this->finalPayload($test));
    }

    public function finalTest(Request $request, QuranFinalTest $finalTest)
    {
        $this->authorizePermission($request, 'quran-final-tests.view');
        $this->authorizeFinalTest($request, $finalTest);

        return response()->json(['data' => $this->finalPayload($finalTest->load(['attempts.teacher', 'enrollment.group.course', 'juz', 'student.parentProfile']))]);
    }

    public function finalEligibleJuz(Request $request, Enrollment $enrollment)
    {
        $this->authorizePermission($request, 'quran-final-tests.record');
        $this->authorizeEnrollment($request, $enrollment);
        $ids = app(QuranFinalTestService::class)->eligibleJuzIdsForStudent($enrollment->student);

        return response()->json(['data' => QuranJuz::query()->whereIn('id', $ids)->orderBy('juz_number')->get()->map(fn (QuranJuz $juz) => $this->juzPayload($juz))->values()]);
    }

    public function storeFinalTest(Request $request, Enrollment $enrollment)
    {
        $this->authorizePermission($request, 'quran-final-tests.record');
        $this->authorizeEnrollment($request, $enrollment);
        OperationalFeatureSettings::ensureMemorizationAndSabersEnabled();
        $validated = $request->validate(['juz_id' => ['required', 'integer', 'exists:quran_juzs,id']]);

        try {
            $test = app(QuranFinalTestService::class)->create($enrollment->load('student'), QuranJuz::query()->findOrFail($validated['juz_id']));
        } catch (LogicException $exception) {
            throw ValidationException::withMessages(['juz_id' => $exception->getMessage()]);
        }

        return response()->json(['data' => $this->finalPayload($test)], 201);
    }

    public function storeFinalAttempt(Request $request, QuranFinalTest $finalTest)
    {
        $this->authorizePermission($request, 'quran-final-tests.record');
        $this->authorizeFinalTest($request, $finalTest);
        OperationalFeatureSettings::ensureMemorizationAndSabersEnabled();
        $validated = $request->validate(['notes' => ['nullable', 'string'], 'score' => ['required', 'numeric', 'between:0,100'], 'teacher_id' => ['nullable', 'integer', 'exists:teachers,id'], 'tested_on' => ['required', 'date']]);

        try {
            $attempt = app(QuranFinalTestService::class)->recordAttempt($finalTest, $this->recordingTeacher($request, $validated, 'quran-final-tests.record'), $validated);
        } catch (LogicException $exception) {
            throw ValidationException::withMessages(['attempt' => $exception->getMessage()]);
        }

        return response()->json(['data' => $this->finalAttemptPayload($attempt)], 201);
    }

    protected function validatedList(Request $request): array
    {
        return $request->validate([
            'group_id' => ['nullable', 'integer', 'exists:groups,id'],
            'juz_id' => ['nullable', 'integer', 'exists:quran_juzs,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status' => ['nullable', Rule::in(['in_progress', 'passed'])],
            'student_id' => ['nullable', 'integer', 'exists:students,id'],
        ]);
    }

    protected function recordingTeacher(Request $request, array $validated, string $permission): Teacher
    {
        if ($request->user()->teacherProfile) {
            return $request->user()->teacherProfile;
        }

        abort_unless($validated['teacher_id'] ?? null, 422);
        $teacher = Teacher::query()->with('user.roles')->where('status', 'active')->findOrFail($validated['teacher_id']);
        abort_unless($request->user()->hasRole('super_admin') || ($teacher->user?->can($permission) && ! app(AccessScopeService::class)->isUnrestricted($teacher->user)), 403);

        return $teacher;
    }

    protected function authorizeEnrollment(Request $request, Enrollment $enrollment): void
    {
        abort_unless($enrollment->status === 'active' && app(AccessScopeService::class)->canAccessEnrollment($request->user(), $enrollment), 403);
    }

    protected function authorizePartialTest(Request $request, QuranPartialTest $test): void
    {
        abort_unless(app(AccessScopeService::class)->scopeQuranPartialTests(QuranPartialTest::query()->whereKey($test->id), $request->user())->exists(), 403);
    }

    protected function authorizeFinalTest(Request $request, QuranFinalTest $test): void
    {
        abort_unless(app(AccessScopeService::class)->scopeQuranFinalTests(QuranFinalTest::query()->whereKey($test->id), $request->user())->exists(), 403);
    }

    protected function partialPayload(QuranPartialTest $test): array
    {
        $test->loadMissing(['enrollment.group.course', 'juz', 'parts.attempts.teacher', 'student']);

        return ['enrollment_id' => $test->enrollment_id, 'group' => $test->enrollment?->group ? ['course_name' => $test->enrollment->group->course?->name, 'id' => $test->enrollment->group->id, 'name' => $test->enrollment->group->name] : null, 'id' => $test->id, 'juz' => $test->juz ? $this->juzPayload($test->juz) : null, 'parts' => $test->parts->map(fn (QuranPartialTestPart $part) => ['attempts' => $part->attempts->map(fn ($attempt) => $this->partialAttemptPayload($attempt))->values(), 'id' => $part->id, 'part_number' => $part->part_number, 'passed_on' => $part->passed_on?->format('Y-m-d'), 'status' => $part->status])->values(), 'passed_on' => $test->passed_on?->format('Y-m-d'), 'status' => $test->status, 'student' => $test->student ? ['id' => $test->student->id, 'name' => $test->student->full_name] : null];
    }

    protected function finalPayload(QuranFinalTest $test): array
    {
        $test->loadMissing(['attempts.teacher', 'enrollment.group.course', 'juz', 'student']);

        return ['attempts' => $test->attempts->map(fn ($attempt) => $this->finalAttemptPayload($attempt))->values(), 'enrollment_id' => $test->enrollment_id, 'group' => $test->enrollment?->group ? ['course_name' => $test->enrollment->group->course?->name, 'id' => $test->enrollment->group->id, 'name' => $test->enrollment->group->name] : null, 'id' => $test->id, 'juz' => $test->juz ? $this->juzPayload($test->juz) : null, 'passed_on' => $test->passed_on?->format('Y-m-d'), 'status' => $test->status, 'student' => $test->student ? ['id' => $test->student->id, 'name' => $test->student->full_name] : null];
    }

    protected function partialAttemptPayload($attempt): array
    {
        return ['attempt_no' => $attempt->attempt_no, 'id' => $attempt->id, 'mistake_count' => $attempt->mistake_count, 'notes' => $attempt->notes, 'status' => $attempt->status, 'teacher' => $attempt->teacher ? ['id' => $attempt->teacher->id, 'name' => $attempt->teacher->full_name] : null, 'tested_on' => $attempt->tested_on?->format('Y-m-d')];
    }

    protected function finalAttemptPayload($attempt): array
    {
        return ['attempt_no' => $attempt->attempt_no, 'id' => $attempt->id, 'notes' => $attempt->notes, 'score' => (float) $attempt->score, 'status' => $attempt->status, 'teacher' => $attempt->teacher ? ['id' => $attempt->teacher->id, 'name' => $attempt->teacher->full_name] : null, 'tested_on' => $attempt->tested_on?->format('Y-m-d')];
    }

    protected function juzPayload(QuranJuz $juz): array
    {
        return ['from_page' => $juz->from_page, 'id' => $juz->id, 'juz_number' => $juz->juz_number, 'to_page' => $juz->to_page];
    }

    protected function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->can($permission), 403);
    }
}
