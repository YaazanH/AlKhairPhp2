<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\ParentProfile;
use App\Models\Teacher;
use App\Services\AccessScopeService;
use App\Support\ArabicSearch;
use App\Support\PhoneNumberFormatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class CoreRecordsController extends Controller
{
    public function parents(Request $request)
    {
        abort_unless($request->user()?->can('parents.view'), 403);
        $validated = $request->validate([
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        return app(AccessScopeService::class)->scopeParents(ParentProfile::query(), $request->user())
            ->withCount('students')
            ->when(array_key_exists('is_active', $validated), fn (Builder $query) => $query->where('is_active', (bool) $validated['is_active']))
            ->when($validated['search'] ?? null, function (Builder $query, string $search): void {
                ArabicSearch::whereAllTokens($query, $search, function (Builder $builder, string $token): void {
                    $like = '%'.$token.'%';
                    $phone = PhoneNumberFormatter::normalize($token);
                    $builder->where('parent_number', 'like', $like)
                        ->orWhere('father_name', 'like', $like)
                        ->orWhere('mother_name', 'like', $like)
                        ->orWhere('father_phone', 'like', $like)
                        ->orWhere('mother_phone', 'like', $like)
                        ->orWhere('home_phone', 'like', $like)
                        ->when($phone, fn (Builder $phoneQuery) => $phoneQuery
                            ->orWhere('father_phone', 'like', '%'.$phone.'%')
                            ->orWhere('mother_phone', 'like', '%'.$phone.'%')
                            ->orWhere('home_phone', 'like', '%'.$phone.'%'));
                });
            })
            ->orderBy('father_name')
            ->paginate($validated['per_page'] ?? 15)
            ->through(fn (ParentProfile $parent) => $this->parentPayload($parent));
    }

    public function parent(Request $request, ParentProfile $parentProfile)
    {
        abort_unless($request->user()?->can('parents.view'), 403);
        abort_unless(app(AccessScopeService::class)->canAccessParent($request->user(), $parentProfile), 403);

        return response()->json(['data' => $this->parentPayload($parentProfile->loadCount('students'))]);
    }

    public function teachers(Request $request)
    {
        abort_unless($request->user()?->can('teachers.view'), 403);
        $validated = $request->validate([
            'course_id' => ['nullable', 'integer', 'exists:courses,id'],
            'is_helping' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:20'],
        ]);

        return app(AccessScopeService::class)->scopeTeachers(Teacher::query(), $request->user())
            ->with(['course', 'jobTitle'])
            ->withCount(['assignedGroups', 'assistedGroups'])
            ->when($validated['course_id'] ?? null, fn (Builder $query, int $courseId) => $query->where('course_id', $courseId))
            ->when(array_key_exists('is_helping', $validated), fn (Builder $query) => $query->where('is_helping', (bool) $validated['is_helping']))
            ->when($validated['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($validated['search'] ?? null, function (Builder $query, string $search): void {
                ArabicSearch::whereAllTokens($query, $search, function (Builder $builder, string $token): void {
                    $like = '%'.$token.'%';
                    $phone = PhoneNumberFormatter::normalize($token);
                    $builder->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        ->when($phone, fn (Builder $phoneQuery) => $phoneQuery->orWhere('phone', 'like', '%'.$phone.'%'));
                });
            })
            ->orderByDesc('is_helping')->orderBy('first_name')->orderBy('last_name')
            ->paginate($validated['per_page'] ?? 15)
            ->through(fn (Teacher $teacher) => $this->teacherPayload($teacher));
    }

    public function teacher(Request $request, Teacher $teacher)
    {
        abort_unless($request->user()?->can('teachers.view'), 403);
        abort_unless(app(AccessScopeService::class)->canAccessTeacher($request->user(), $teacher), 403);

        $teacher->load(['course', 'jobTitle'])->loadCount(['assignedGroups', 'assistedGroups']);

        return response()->json(['data' => $this->teacherPayload($teacher)]);
    }

    public function courses(Request $request)
    {
        abort_unless($request->user()?->can('courses.view'), 403);
        $validated = $request->validate([
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        return Course::query()->with('academicYear')->withCount(['groups', 'enrollments'])
            ->when($validated['academic_year_id'] ?? null, fn (Builder $query, int $yearId) => $query->where('academic_year_id', $yearId))
            ->when(array_key_exists('is_active', $validated), fn (Builder $query) => $query->where('is_active', (bool) $validated['is_active']))
            ->when($validated['search'] ?? null, fn (Builder $query, string $search) => $query->where('name', 'like', '%'.$search.'%'))
            ->orderByDesc('is_default')->orderBy('name')
            ->paginate($validated['per_page'] ?? 15)
            ->through(fn (Course $course) => $this->coursePayload($course));
    }

    public function course(Request $request, Course $course)
    {
        abort_unless($request->user()?->can('courses.view'), 403);
        $course->load('academicYear')->loadCount(['groups', 'enrollments']);

        return response()->json(['data' => $this->coursePayload($course)]);
    }

    public function parentPayload(ParentProfile $parent): array
    {
        return [
            'address' => $parent->address,
            'father_name' => $parent->father_name,
            'father_phone' => $parent->father_phone,
            'father_work' => $parent->father_work,
            'home_phone' => $parent->home_phone,
            'id' => $parent->id,
            'is_active' => $parent->is_active,
            'mother_name' => $parent->mother_name,
            'mother_phone' => $parent->mother_phone,
            'notes' => $parent->notes,
            'parent_number' => $parent->parent_number,
            'students_count' => (int) ($parent->students_count ?? $parent->students()->count()),
        ];
    }

    public function teacherPayload(Teacher $teacher): array
    {
        return [
            'assigned_groups_count' => (int) ($teacher->assigned_groups_count ?? $teacher->assignedGroups()->count()),
            'assisted_groups_count' => (int) ($teacher->assisted_groups_count ?? $teacher->assistedGroups()->count()),
            'course' => $teacher->course ? ['id' => $teacher->course->id, 'name' => $teacher->course->name] : null,
            'first_name' => $teacher->first_name,
            'hired_at' => $teacher->hired_at?->format('Y-m-d'),
            'id' => $teacher->id,
            'is_helping' => $teacher->is_helping,
            'job_title' => $teacher->jobTitle?->name ?? $teacher->job_title,
            'last_name' => $teacher->last_name,
            'notes' => $teacher->notes,
            'phone' => $teacher->phone,
            'photo_url' => $teacher->photo_path ? '/storage/'.ltrim($teacher->photo_path, '/') : null,
            'status' => $teacher->status,
            'teacher_job_title_id' => $teacher->teacher_job_title_id,
        ];
    }

    public function coursePayload(Course $course): array
    {
        return [
            'academic_year' => $course->academicYear?->name,
            'academic_year_id' => $course->academic_year_id,
            'awards_points' => $course->awards_points,
            'description' => $course->description,
            'ends_on' => $course->ends_on?->format('Y-m-d'),
            'enrollments_count' => (int) ($course->enrollments_count ?? $course->enrollments()->count()),
            'finished_at' => $course->finished_at?->toIso8601String(),
            'groups_count' => (int) ($course->groups_count ?? $course->groups()->count()),
            'id' => $course->id,
            'is_active' => $course->is_active,
            'is_default' => $course->is_default,
            'name' => $course->name,
            'starts_on' => $course->starts_on?->format('Y-m-d'),
        ];
    }
}
