<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Invoice;
use App\Models\Student;
use App\Services\AccessScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class RecordsController extends Controller
{
    /**
     * Return paginated activity records for integrations.
     */
    public function activities(Request $request)
    {
        abort_unless($request->user()?->can('activities.view'), 403);

        $validated = $request->validate([
            'group_id' => ['nullable', 'integer', 'exists:groups,id'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return app(AccessScopeService::class)
            ->scopeActivities(Activity::query(), $request->user())
            ->with(['group.course'])
            ->when(array_key_exists('is_active', $validated), fn (Builder $query) => $query->where('is_active', (bool) $validated['is_active']))
            ->when($validated['group_id'] ?? null, fn (Builder $query, int $groupId) => $query->where('group_id', $groupId))
            ->orderByDesc('activity_date')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 15)
            ->through(fn (Activity $activity) => [
                'activity_date' => $activity->activity_date?->format('Y-m-d'),
                'collected_revenue' => (float) $activity->collected_revenue_cached,
                'description' => $activity->description,
                'expected_revenue' => (float) $activity->expected_revenue_cached,
                'expense_total' => (float) $activity->expense_total_cached,
                'fee_amount' => (float) $activity->fee_amount,
                'group' => $activity->group ? [
                    'course_name' => $activity->group->course?->name,
                    'id' => $activity->group->id,
                    'name' => $activity->group->name,
                ] : null,
                'id' => $activity->id,
                'is_active' => $activity->is_active,
                'title' => $activity->title,
            ]);
    }

    /**
     * Return paginated assessment records for integrations.
     */
    public function assessments(Request $request)
    {
        abort_unless($request->user()?->can('assessments.view'), 403);

        $validated = $request->validate([
            'assessment_type_id' => ['nullable', 'integer', 'exists:assessment_types,id'],
            'group_id' => ['nullable', 'integer', 'exists:groups,id'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = app(AccessScopeService::class)
            ->scopeAssessments(Assessment::query(), $request->user())
            ->with(['group.course', 'type'])
            ->withCount('results')
            ->when(array_key_exists('is_active', $validated), fn (Builder $builder) => $builder->where('is_active', (bool) $validated['is_active']))
            ->when($validated['assessment_type_id'] ?? null, fn (Builder $builder, int $typeId) => $builder->where('assessment_type_id', $typeId))
            ->when($validated['group_id'] ?? null, fn (Builder $builder, int $groupId) => $builder->where('group_id', $groupId))
            ->orderByDesc('scheduled_at')
            ->orderByDesc('id');

        return $query->paginate($validated['per_page'] ?? 15)
            ->through(fn (Assessment $assessment) => [
                'description' => $assessment->description,
                'due_at' => $assessment->due_at?->toIso8601String(),
                'group' => $assessment->group ? [
                    'course_name' => $assessment->group->course?->name,
                    'id' => $assessment->group->id,
                    'name' => $assessment->group->name,
                ] : null,
                'id' => $assessment->id,
                'is_active' => $assessment->is_active,
                'pass_mark' => $assessment->pass_mark !== null ? (float) $assessment->pass_mark : null,
                'results_count' => $assessment->results_count,
                'scheduled_at' => $assessment->scheduled_at?->toIso8601String(),
                'title' => $assessment->title,
                'total_mark' => $assessment->total_mark !== null ? (float) $assessment->total_mark : null,
                'type' => $assessment->type?->name,
            ]);
    }

    /**
     * Return paginated enrollment records for integrations.
     */
    public function enrollments(Request $request)
    {
        abort_unless($request->user()?->can('enrollments.view'), 403);

        $validated = $request->validate([
            'group_id' => ['nullable', 'integer', 'exists:groups,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status' => ['nullable', 'string', 'max:50'],
            'student_id' => ['nullable', 'integer', 'exists:students,id'],
        ]);

        return app(AccessScopeService::class)
            ->scopeEnrollments(Enrollment::query(), $request->user())
            ->with(['group.course', 'student.parentProfile'])
            ->when($validated['group_id'] ?? null, fn (Builder $query, int $groupId) => $query->where('group_id', $groupId))
            ->when($validated['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($validated['student_id'] ?? null, fn (Builder $query, int $studentId) => $query->where('student_id', $studentId))
            ->orderByDesc('enrolled_at')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 15)
            ->through(fn (Enrollment $enrollment) => [
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
                'status' => $enrollment->status,
                'student' => $enrollment->student ? [
                    'full_name' => trim($enrollment->student->first_name.' '.$enrollment->student->last_name),
                    'id' => $enrollment->student->id,
                    'parent_name' => $enrollment->student->parentProfile?->father_name,
                ] : null,
            ]);
    }

    /**
     * Return paginated group records for integrations.
     */
    public function groups(Request $request)
    {
        abort_unless($request->user()?->can('groups.view'), 403);

        $validated = $request->validate([
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'teacher_id' => ['nullable', 'integer', 'exists:teachers,id'],
        ]);

        return app(AccessScopeService::class)
            ->scopeGroups(Group::query(), $request->user())
            ->with(['academicYear', 'course', 'teacher', 'assistantTeacher'])
            ->withCount('enrollments')
            ->when($validated['academic_year_id'] ?? null, fn (Builder $query, int $yearId) => $query->where('academic_year_id', $yearId))
            ->when(array_key_exists('is_active', $validated), fn (Builder $query) => $query->where('is_active', (bool) $validated['is_active']))
            ->when($validated['teacher_id'] ?? null, function (Builder $query, int $teacherId) {
                $query->where(function (Builder $builder) use ($teacherId) {
                    $builder
                        ->where('teacher_id', $teacherId)
                        ->orWhere('assistant_teacher_id', $teacherId);
                });
            })
            ->orderBy('name')
            ->paginate($validated['per_page'] ?? 15)
            ->through(fn (Group $group) => [
                'academic_year' => $group->academicYear?->name,
                'assistant_teacher' => $group->assistantTeacher ? trim($group->assistantTeacher->first_name.' '.$group->assistantTeacher->last_name) : null,
                'capacity' => $group->capacity,
                'course' => $group->course?->name,
                'ends_on' => $group->ends_on?->format('Y-m-d'),
                'enrollments_count' => $group->enrollments_count,
                'id' => $group->id,
                'is_active' => $group->is_active,
                'name' => $group->name,
                'starts_on' => $group->starts_on?->format('Y-m-d'),
                'teacher' => $group->teacher ? trim($group->teacher->first_name.' '.$group->teacher->last_name) : null,
            ]);
    }

    /**
     * Return paginated invoice records for integrations.
     */
    public function invoices(Request $request)
    {
        abort_unless($request->user()?->can('invoices.view'), 403);

        $validated = $request->validate([
            'invoice_type' => ['nullable', 'string', 'max:50'],
            'parent_id' => ['nullable', 'integer', 'exists:parents,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status' => ['nullable', 'string', 'max:50'],
        ]);

        return app(AccessScopeService::class)
            ->scopeInvoices(Invoice::query(), $request->user())
            ->with('parentProfile')
            ->withCount('items')
            ->withSum(['payments as paid_total' => fn (Builder $query) => $query->whereNull('voided_at')], 'amount')
            ->when($validated['invoice_type'] ?? null, fn (Builder $query, string $type) => $query->where('invoice_type', $type))
            ->when($validated['parent_id'] ?? null, fn (Builder $query, int $parentId) => $query->where('parent_id', $parentId))
            ->when($validated['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 15)
            ->through(fn (Invoice $invoice) => [
                'balance' => round(((float) $invoice->total) - ((float) ($invoice->paid_total ?? 0)), 2),
                'discount' => (float) $invoice->discount,
                'due_date' => $invoice->due_date?->format('Y-m-d'),
                'id' => $invoice->id,
                'invoice_no' => $invoice->invoice_no,
                'invoice_type' => $invoice->invoice_type,
                'issue_date' => $invoice->issue_date?->format('Y-m-d'),
                'items_count' => $invoice->items_count,
                'parent' => $invoice->parentProfile?->father_name,
                'paid_total' => round((float) ($invoice->paid_total ?? 0), 2),
                'status' => $invoice->status,
                'total' => (float) $invoice->total,
            ]);
    }

    /**
     * Return paginated student records for integrations.
     */
    public function students(Request $request)
    {
        abort_unless($request->user()?->can('students.view'), 403);

        $validated = $request->validate([
            'group_id' => ['nullable', 'integer', 'exists:groups,id'],
            'parent_id' => ['nullable', 'integer', 'exists:parents,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status' => ['nullable', 'string', 'max:50'],
        ]);

        return app(AccessScopeService::class)
            ->scopeStudents(Student::query(), $request->user())
            ->with(['gradeLevel', 'parentProfile', 'quranCurrentJuz'])
            ->withCount('enrollments')
            ->when($validated['group_id'] ?? null, function (Builder $query, int $groupId) {
                $query->whereHas('enrollments', fn (Builder $builder) => $builder->where('group_id', $groupId));
            })
            ->when($validated['parent_id'] ?? null, fn (Builder $query, int $parentId) => $query->where('parent_id', $parentId))
            ->when($validated['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate($validated['per_page'] ?? 15)
            ->through(fn (Student $student) => [
                'birth_date' => $student->birth_date?->format('Y-m-d'),
                'current_juz' => $student->quranCurrentJuz?->juz_number,
                'enrollments_count' => $student->enrollments_count,
                'first_name' => $student->first_name,
                'grade_level' => $student->gradeLevel?->name,
                'id' => $student->id,
                'last_name' => $student->last_name,
                'parent' => $student->parentProfile?->father_name,
                'school_name' => $student->school_name,
                'status' => $student->status,
            ]);
    }
}
