<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Group;
use App\Models\ParentProfile;
use App\Models\Teacher;
use App\Services\AccessScopeService;
use App\Services\CourseLifecycleService;
use App\Services\ManagedUserService;
use App\Support\ArabicSearch;
use App\Support\PhoneNumberFormatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CoreWriteController extends Controller
{
    public function storeParent(Request $request)
    {
        $this->authorizePermission($request, 'parents.create');
        $validated = $this->validatedParentData($request);
        $this->ensureParentIsNotDuplicate($validated);

        $parent = DB::transaction(function () use ($validated): ParentProfile {
            $parent = ParentProfile::query()->create($validated);
            $result = app(ManagedUserService::class)->syncLinkedUser(null, $this->parentAccountPayload($parent, $validated), 'parent');
            $parent->user()->associate($result['user']);
            $parent->save();

            return $parent;
        });

        return response()->json(['data' => app(CoreRecordsController::class)->parentPayload($parent->fresh()->loadCount('students'))], 201);
    }

    public function updateParent(Request $request, ParentProfile $parentProfile)
    {
        $this->authorizePermission($request, 'parents.update');
        $this->authorizeScope($request, 'canAccessParent', $parentProfile);
        $validated = $this->validatedParentData($request);
        $this->ensureParentIsNotDuplicate($validated, $parentProfile);

        DB::transaction(function () use ($parentProfile, $validated): void {
            $parentProfile->update($validated);
            $result = app(ManagedUserService::class)->syncLinkedUser(
                $parentProfile->user,
                $this->parentAccountPayload($parentProfile, $validated),
                'parent',
            );
            $parentProfile->user()->associate($result['user']);
            $parentProfile->save();
        });

        return response()->json(['data' => app(CoreRecordsController::class)->parentPayload($parentProfile->fresh()->loadCount('students'))]);
    }

    public function destroyParent(Request $request, ParentProfile $parentProfile)
    {
        $this->authorizePermission($request, 'parents.delete');
        $this->authorizeScope($request, 'canAccessParent', $parentProfile);

        if ($parentProfile->students()->exists()) {
            return response()->json(['message' => 'This parent cannot be deleted while students are linked.'], 422);
        }

        DB::transaction(function () use ($parentProfile): void {
            $linkedUser = $parentProfile->user;
            $parentProfile->delete();
            app(ManagedUserService::class)->removeProfileAccount($linkedUser, 'parent');
        });

        return response()->noContent();
    }

    public function storeTeacher(Request $request)
    {
        $this->authorizePermission($request, 'teachers.create');
        $validated = $this->validatedTeacherData($request);

        $teacher = DB::transaction(fn (): Teacher => $this->persistTeacher(new Teacher, $validated));

        return response()->json(['data' => app(CoreRecordsController::class)->teacherPayload(
            $teacher->fresh(['course', 'jobTitle'])->loadCount(['assignedGroups', 'assistedGroups'])
        )], 201);
    }

    public function updateTeacher(Request $request, Teacher $teacher)
    {
        $this->authorizePermission($request, 'teachers.update');
        $this->authorizeScope($request, 'canAccessTeacher', $teacher);
        $validated = $this->validatedTeacherData($request, $teacher);

        DB::transaction(fn (): Teacher => $this->persistTeacher($teacher, $validated));

        return response()->json(['data' => app(CoreRecordsController::class)->teacherPayload(
            $teacher->fresh(['course', 'jobTitle'])->loadCount(['assignedGroups', 'assistedGroups'])
        )]);
    }

    public function destroyTeacher(Request $request, Teacher $teacher)
    {
        $this->authorizePermission($request, 'teachers.delete');
        $this->authorizeScope($request, 'canAccessTeacher', $teacher);

        if ($teacher->assignedGroups()->exists() || $teacher->assistedGroups()->exists()) {
            return response()->json(['message' => 'This teacher cannot be deleted while groups are assigned.'], 422);
        }

        DB::transaction(function () use ($teacher): void {
            $linkedUser = $teacher->user;
            $teacher->delete();
            app(ManagedUserService::class)->removeProfileAccount($linkedUser, 'teacher');
        });

        return response()->noContent();
    }

    public function storeCourse(Request $request)
    {
        $this->authorizePermission($request, 'courses.create');
        $validated = $this->validatedCourseData($request);
        $validated['is_active'] = true;
        $validated['is_default'] = $validated['is_default'] || Course::query()->where('is_default', true)->doesntExist();

        $course = DB::transaction(fn (): Course => Course::query()->create($validated));

        return response()->json(['data' => app(CoreRecordsController::class)->coursePayload(
            $course->fresh('academicYear')->loadCount(['groups', 'enrollments'])
        )], 201);
    }

    public function updateCourse(Request $request, Course $course)
    {
        $this->authorizePermission($request, 'courses.update');

        if (! $course->is_active) {
            throw ValidationException::withMessages(['course' => __('crud.courses.errors.finished_read_only')]);
        }

        $validated = $this->validatedCourseData($request, $course);
        $validated['is_active'] = true;
        $validated['is_default'] = $validated['is_default']
            || Course::query()->whereKeyNot($course->id)->where('is_default', true)->doesntExist();

        DB::transaction(function () use ($course, $validated): void {
            $previousAcademicYearId = $course->academic_year_id;
            $course->update($validated);

            if ($previousAcademicYearId !== $course->academic_year_id) {
                Group::withTrashed()->where('course_id', $course->id)->update(['academic_year_id' => $course->academic_year_id]);
            }
        });

        return response()->json(['data' => app(CoreRecordsController::class)->coursePayload(
            $course->fresh('academicYear')->loadCount(['groups', 'enrollments'])
        )]);
    }

    public function destroyCourse(Request $request, Course $course)
    {
        $this->authorizePermission($request, 'courses.delete');

        if (! $this->courseCanBeDeleted($course)) {
            return response()->json(['message' => 'This course cannot be deleted while linked records exist.'], 422);
        }

        DB::transaction(function () use ($course): void {
            Group::withTrashed()->where('course_id', $course->id)->get()->each->forceDelete();
            $course->delete();
            Course::query()->where('is_default', true)->exists()
                ?: Course::query()->where('is_active', true)->orderBy('name')->first()?->update(['is_default' => true]);
        });

        return response()->noContent();
    }

    public function finishCourse(Request $request, Course $course)
    {
        $this->authorizePermission($request, 'courses.update');
        if (! $course->is_active) {
            throw ValidationException::withMessages(['course' => 'The course is already finished.']);
        }
        app(CourseLifecycleService::class)->finish($course);

        return response()->json(['data' => app(CoreRecordsController::class)->coursePayload(
            $course->fresh('academicYear')->loadCount(['groups', 'enrollments'])
        )]);
    }

    public function reactivateCourse(Request $request, Course $course)
    {
        $this->authorizePermission($request, 'courses.update');
        if ($course->is_active) {
            throw ValidationException::withMessages(['course' => 'The course is already active.']);
        }
        app(CourseLifecycleService::class)->reactivate($course);

        return response()->json(['data' => app(CoreRecordsController::class)->coursePayload(
            $course->fresh('academicYear')->loadCount(['groups', 'enrollments'])
        )]);
    }

    protected function validatedParentData(Request $request): array
    {
        $validated = $request->validate([
            'address' => ['nullable', 'string', 'max:255'],
            'father_name' => ['required', 'string', 'max:255'],
            'father_phone' => ['nullable', 'string', 'max:30'],
            'father_work' => ['nullable', 'string', 'max:255'],
            'home_phone' => ['nullable', 'string', 'max:30'],
            'is_active' => ['sometimes', 'boolean'],
            'mother_name' => ['nullable', 'string', 'max:255'],
            'mother_phone' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string'],
        ]);

        foreach (['father_phone', 'mother_phone', 'home_phone'] as $field) {
            $validated[$field] = PhoneNumberFormatter::normalize($validated[$field] ?? null);
        }
        foreach (['address', 'father_work', 'mother_name', 'notes'] as $field) {
            $validated[$field] = filled($validated[$field] ?? null) ? $validated[$field] : null;
        }
        $validated['is_active'] = $validated['is_active'] ?? true;

        return $validated;
    }

    protected function validatedTeacherData(Request $request, ?Teacher $teacher = null): array
    {
        $validated = $request->validate([
            'account_is_active' => ['sometimes', 'boolean'],
            'account_password' => ['nullable', 'string', 'min:8'],
            'account_username' => ['nullable', 'string', 'max:255', Rule::unique('users', 'username')->ignore($teacher?->user_id)],
            'course_id' => ['nullable', 'integer', Rule::exists('courses', 'id')->whereNull('deleted_at')],
            'first_name' => ['required', 'string', 'max:255'],
            'hired_at' => ['nullable', 'date'],
            'is_helping' => ['sometimes', 'boolean'],
            'last_name' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'phone' => ['required', 'string', 'max:30'],
            'teacher_job_title_id' => ['nullable', 'integer', Rule::exists('teacher_job_titles', 'id')],
        ]);

        $validated['phone'] = PhoneNumberFormatter::normalize($validated['phone']) ?? '';
        $validated['account_is_active'] = $validated['account_is_active'] ?? ($teacher?->user?->is_active ?? true);
        $validated['course_id'] = $validated['course_id'] ?? null;
        $validated['hired_at'] = $validated['hired_at'] ?? null;
        $validated['is_helping'] = $validated['is_helping'] ?? false;
        $validated['notes'] = filled($validated['notes'] ?? null) ? $validated['notes'] : null;
        $validated['status'] = $teacher
            ? ($validated['account_is_active'] ? 'active' : 'inactive')
            : 'active';
        $validated['teacher_job_title_id'] = $validated['teacher_job_title_id'] ?? null;

        return $validated;
    }

    protected function validatedCourseData(Request $request, ?Course $course = null): array
    {
        $validated = $request->validate([
            'academic_year_id' => [
                'required',
                'integer',
                Rule::exists('academic_years', 'id')->where(fn ($query) => $query
                    ->where('is_active', true)
                    ->when($course, fn ($yearQuery) => $yearQuery->orWhere('id', $course->academic_year_id))),
            ],
            'awards_points' => ['sometimes', 'boolean'],
            'description' => ['nullable', 'string'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'is_default' => ['sometimes', 'boolean'],
            'name' => ['required', 'string', 'max:255', Rule::unique('courses', 'name')->ignore($course?->id)],
            'starts_on' => ['required', 'date'],
        ]);

        $validated['awards_points'] = $validated['awards_points'] ?? false;
        $validated['description'] = filled($validated['description'] ?? null) ? $validated['description'] : null;
        $validated['is_default'] = $validated['is_default'] ?? false;

        return $validated;
    }

    protected function persistTeacher(Teacher $teacher, array $validated): Teacher
    {
        $teacher->fill([
            'course_id' => $validated['course_id'],
            'first_name' => $validated['first_name'],
            'hired_at' => $validated['hired_at'],
            'is_helping' => $validated['is_helping'],
            'last_name' => $validated['last_name'],
            'notes' => $validated['notes'],
            'phone' => $validated['phone'],
            'status' => $validated['status'],
            'teacher_job_title_id' => $validated['teacher_job_title_id'],
        ])->save();

        $result = app(ManagedUserService::class)->syncLinkedUser($teacher->user, [
            'is_active' => $validated['account_is_active'],
            'name' => trim($validated['first_name'].' '.$validated['last_name']),
            'password' => $validated['account_password'] ?? null,
            'phone' => $validated['phone'],
            'username' => $validated['account_username'] ?? null,
        ], 'teacher', true);
        $teacher->user()->associate($result['user']);
        $teacher->save();

        return $teacher;
    }

    protected function parentAccountPayload(ParentProfile $parent, array $validated): array
    {
        return [
            'is_active' => $parent->user?->is_active ?? (bool) $validated['is_active'],
            'name' => $validated['father_name'],
            'phones' => [$validated['father_phone'], $validated['mother_phone'], $validated['home_phone']],
            'username' => $parent->parent_number,
        ];
    }

    protected function ensureParentIsNotDuplicate(array $validated, ?ParentProfile $parent = null): void
    {
        $fatherName = ArabicSearch::normalizeForDuplicate((string) $validated['father_name']);
        $motherName = ArabicSearch::normalizeForDuplicate((string) ($validated['mother_name'] ?? ''));
        $phones = collect([$validated['father_phone'], $validated['mother_phone'], $validated['home_phone']])
            ->map(fn ($phone) => preg_replace('/\D+/', '', (string) $phone) ?: null)->filter();

        $duplicate = ParentProfile::query()->when($parent, fn (Builder $query) => $query->whereKeyNot($parent->id))
            ->get()->first(function (ParentProfile $candidate) use ($fatherName, $motherName, $phones): bool {
                $candidatePhones = collect([$candidate->father_phone, $candidate->mother_phone, $candidate->home_phone])
                    ->map(fn ($phone) => preg_replace('/\D+/', '', (string) $phone) ?: null)->filter();

                return ($phones->isNotEmpty() && $phones->intersect($candidatePhones)->isNotEmpty())
                    || ($fatherName !== '' && $motherName !== ''
                        && ArabicSearch::normalizeForDuplicate($candidate->father_name) === $fatherName
                        && ArabicSearch::normalizeForDuplicate((string) $candidate->mother_name) === $motherName);
            });

        if ($duplicate) {
            throw ValidationException::withMessages(['father_name' => __('crud.parents.errors.duplicate_profile', [
                'name' => $duplicate->father_name,
                'number' => $duplicate->parent_number ?: $duplicate->id,
            ])]);
        }
    }

    protected function courseCanBeDeleted(Course $course): bool
    {
        $groupIds = Group::withTrashed()->where('course_id', $course->id)->pluck('id');
        if ($course->curricula()->exists()) {
            return false;
        }
        foreach (['barcode_scan_imports', 'student_attendance_days', 'teacher_attendance_days', 'student_card_prints'] as $table) {
            if (DB::table($table)->where('course_id', $course->id)->exists()) {
                return false;
            }
        }
        foreach (['enrollments', 'assessments', 'group_attendance_days', 'activities', 'group_curriculum_lesson_progresses', 'group_curriculum_topic_progresses', 'group_custom_curriculum_lessons'] as $table) {
            if ($groupIds->isNotEmpty() && DB::table($table)->whereIn('group_id', $groupIds)->exists()) {
                return false;
            }
        }

        return true;
    }

    protected function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->can($permission), 403);
    }

    protected function authorizeScope(Request $request, string $method, ParentProfile|Teacher $record): void
    {
        abort_unless(app(AccessScopeService::class)->{$method}($request->user(), $record), 403);
    }
}
