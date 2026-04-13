<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WriteRecordsController extends Controller
{
    /**
     * Create a student record for integrations.
     */
    public function storeStudent(Request $request)
    {
        $this->authorizePermission($request, 'students.create');

        $student = Student::create($this->validatedStudentData($request));

        return response()->json($this->studentPayload($student->fresh(['gradeLevel', 'parentProfile', 'quranCurrentJuz'])), 201);
    }

    /**
     * Update a student record for integrations.
     */
    public function updateStudent(Request $request, Student $student)
    {
        $this->authorizePermission($request, 'students.update');

        $student->update($this->validatedStudentData($request, $student));

        return response()->json($this->studentPayload($student->fresh(['gradeLevel', 'parentProfile', 'quranCurrentJuz'])));
    }

    /**
     * Delete a student record when no enrollments remain.
     */
    public function destroyStudent(Request $request, Student $student)
    {
        $this->authorizePermission($request, 'students.delete');

        if ($student->enrollments()->count() > 0) {
            return response()->json([
                'message' => 'This student cannot be deleted while enrollments still exist.',
            ], 422);
        }

        $student->delete();

        return response()->noContent();
    }

    /**
     * Create a group record for integrations.
     */
    public function storeGroup(Request $request)
    {
        $this->authorizePermission($request, 'groups.create');

        $group = Group::create($this->validatedGroupData($request));

        return response()->json($this->groupPayload($group->fresh(['academicYear', 'course', 'teacher', 'assistantTeacher', 'gradeLevel'])), 201);
    }

    /**
     * Update a group record for integrations.
     */
    public function updateGroup(Request $request, Group $group)
    {
        $this->authorizePermission($request, 'groups.update');

        $group->update($this->validatedGroupData($request, $group));

        return response()->json($this->groupPayload($group->fresh(['academicYear', 'course', 'teacher', 'assistantTeacher', 'gradeLevel'])));
    }

    /**
     * Delete a group record when enrollments and schedules do not exist.
     */
    public function destroyGroup(Request $request, Group $group)
    {
        $this->authorizePermission($request, 'groups.delete');

        if ($group->enrollments()->count() > 0 || $group->schedules()->count() > 0) {
            return response()->json([
                'message' => 'This group cannot be deleted while enrollments or schedules still exist.',
            ], 422);
        }

        $group->delete();

        return response()->noContent();
    }

    /**
     * Create an enrollment record for integrations.
     */
    public function storeEnrollment(Request $request)
    {
        $this->authorizePermission($request, 'enrollments.create');

        $enrollment = Enrollment::create($this->validatedEnrollmentData($request));

        return response()->json($this->enrollmentPayload($enrollment->fresh(['group.course', 'student.parentProfile'])), 201);
    }

    /**
     * Update an enrollment record for integrations.
     */
    public function updateEnrollment(Request $request, Enrollment $enrollment)
    {
        $this->authorizePermission($request, 'enrollments.update');

        $enrollment->update($this->validatedEnrollmentData($request, $enrollment));

        return response()->json($this->enrollmentPayload($enrollment->fresh(['group.course', 'student.parentProfile'])));
    }

    /**
     * Delete an enrollment record for integrations.
     */
    public function destroyEnrollment(Request $request, Enrollment $enrollment)
    {
        $this->authorizePermission($request, 'enrollments.delete');

        $enrollment->delete();

        return response()->noContent();
    }

    protected function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->can($permission), 403);
    }

    protected function enrollmentPayload(Enrollment $enrollment): array
    {
        return [
            'enrolled_at' => $enrollment->enrolled_at?->format('Y-m-d'),
            'final_points' => $enrollment->final_points_cached,
            'group' => $enrollment->group ? [
                'course_name' => $enrollment->group->course?->name,
                'id' => $enrollment->group->id,
                'name' => $enrollment->group->name,
            ] : null,
            'id' => $enrollment->id,
            'left_at' => $enrollment->left_at?->format('Y-m-d'),
            'memorized_pages' => $enrollment->memorized_pages_cached,
            'notes' => $enrollment->notes,
            'status' => $enrollment->status,
            'student' => $enrollment->student ? [
                'full_name' => trim($enrollment->student->first_name.' '.$enrollment->student->last_name),
                'id' => $enrollment->student->id,
                'parent_name' => $enrollment->student->parentProfile?->father_name,
            ] : null,
        ];
    }

    protected function groupPayload(Group $group): array
    {
        return [
            'academic_year' => $group->academicYear?->name,
            'academic_year_id' => $group->academic_year_id,
            'assistant_teacher' => $group->assistantTeacher ? trim($group->assistantTeacher->first_name.' '.$group->assistantTeacher->last_name) : null,
            'assistant_teacher_id' => $group->assistant_teacher_id,
            'capacity' => $group->capacity,
            'course' => $group->course?->name,
            'course_id' => $group->course_id,
            'ends_on' => $group->ends_on?->format('Y-m-d'),
            'grade_level' => $group->gradeLevel?->name,
            'grade_level_id' => $group->grade_level_id,
            'id' => $group->id,
            'is_active' => $group->is_active,
            'monthly_fee' => $group->monthly_fee !== null ? (float) $group->monthly_fee : null,
            'name' => $group->name,
            'starts_on' => $group->starts_on?->format('Y-m-d'),
            'teacher' => $group->teacher ? trim($group->teacher->first_name.' '.$group->teacher->last_name) : null,
            'teacher_id' => $group->teacher_id,
        ];
    }

    protected function studentPayload(Student $student): array
    {
        return [
            'birth_date' => $student->birth_date?->format('Y-m-d'),
            'first_name' => $student->first_name,
            'gender' => $student->gender,
            'grade_level' => $student->gradeLevel?->name,
            'grade_level_id' => $student->grade_level_id,
            'id' => $student->id,
            'joined_at' => $student->joined_at?->format('Y-m-d'),
            'last_name' => $student->last_name,
            'notes' => $student->notes,
            'parent' => $student->parentProfile?->father_name,
            'parent_id' => $student->parent_id,
            'photo_path' => $student->photo_path,
            'quran_current_juz' => $student->quranCurrentJuz?->juz_number,
            'quran_current_juz_id' => $student->quran_current_juz_id,
            'school_name' => $student->school_name,
            'student_number' => $student->student_number,
            'status' => $student->status,
        ];
    }

    protected function validatedEnrollmentData(Request $request, ?Enrollment $enrollment = null): array
    {
        $validated = $request->validate([
            'enrolled_at' => [
                'required',
                'date',
                Rule::unique('enrollments', 'enrolled_at')
                    ->where(fn ($query) => $query
                        ->where('student_id', $request->input('student_id'))
                        ->where('group_id', $request->input('group_id')))
                    ->ignore($enrollment?->id),
            ],
            'group_id' => ['required', 'integer', Rule::exists('groups', 'id')->whereNull('deleted_at')],
            'left_at' => ['nullable', 'date', 'after_or_equal:enrolled_at'],
            'notes' => ['nullable', 'string'],
            'status' => ['required', Rule::in(['active', 'completed', 'cancelled'])],
            'student_id' => ['required', 'integer', Rule::exists('students', 'id')->whereNull('deleted_at')],
        ]);

        $validated['left_at'] = $validated['left_at'] ?? null;

        return $validated;
    }

    protected function validatedGroupData(Request $request, ?Group $group = null): array
    {
        $validated = $request->validate([
            'academic_year_id' => ['required', 'integer', Rule::exists('academic_years', 'id')],
            'assistant_teacher_id' => ['nullable', 'integer', Rule::exists('teachers', 'id')->whereNull('deleted_at'), 'different:teacher_id'],
            'capacity' => ['required', 'integer', 'min:0'],
            'course_id' => ['required', 'integer', Rule::exists('courses', 'id')->whereNull('deleted_at')],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'grade_level_id' => ['nullable', 'integer', Rule::exists('grade_levels', 'id')],
            'is_active' => ['sometimes', 'boolean'],
            'monthly_fee' => ['nullable', 'numeric', 'min:0'],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('groups', 'name')
                    ->where(fn ($query) => $query->where('academic_year_id', $request->input('academic_year_id')))
                    ->ignore($group?->id),
            ],
            'starts_on' => ['nullable', 'date'],
            'teacher_id' => ['required', 'integer', Rule::exists('teachers', 'id')->whereNull('deleted_at')],
        ]);

        $validated['assistant_teacher_id'] = $validated['assistant_teacher_id'] ?? null;
        $validated['ends_on'] = $validated['ends_on'] ?? null;
        $validated['grade_level_id'] = $validated['grade_level_id'] ?? null;
        $validated['is_active'] = $validated['is_active'] ?? true;
        $validated['monthly_fee'] = $validated['monthly_fee'] ?? null;
        $validated['starts_on'] = $validated['starts_on'] ?? null;

        return $validated;
    }

    protected function validatedStudentData(Request $request, ?Student $student = null): array
    {
        $validated = $request->validate([
            'birth_date' => ['required', 'date'],
            'first_name' => ['required', 'string', 'max:255'],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
            'grade_level_id' => ['nullable', 'integer', Rule::exists('grade_levels', 'id')],
            'joined_at' => ['nullable', 'date'],
            'last_name' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'parent_id' => ['required', 'integer', Rule::exists('parents', 'id')->whereNull('deleted_at')],
            'photo_path' => ['nullable', 'string', 'max:255'],
            'quran_current_juz_id' => ['nullable', 'integer', Rule::exists('quran_juzs', 'id')],
            'school_name' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(['active', 'inactive', 'graduated', 'blocked'])],
        ]);

        $validated['gender'] = $validated['gender'] ?? null;
        $validated['grade_level_id'] = $validated['grade_level_id'] ?? null;
        $validated['joined_at'] = $validated['joined_at'] ?? null;
        $validated['notes'] = $validated['notes'] ?? null;
        $validated['photo_path'] = $validated['photo_path'] ?? null;
        $validated['quran_current_juz_id'] = $validated['quran_current_juz_id'] ?? null;
        $validated['school_name'] = $validated['school_name'] ?? null;

        return $validated;
    }
}
