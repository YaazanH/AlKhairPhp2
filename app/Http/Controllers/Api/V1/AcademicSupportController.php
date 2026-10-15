<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Curriculum;
use App\Models\CurriculumResource;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\GroupSchedule;
use App\Models\Student;
use App\Models\StudentNote;
use App\Services\AccessScopeService;
use App\Services\CurriculumAccessService;
use App\Services\CurriculumProgressService;
use App\Support\ScheduleTimeSlots;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AcademicSupportController extends Controller
{
    public function groupSchedules(Request $request, Group $group)
    {
        $this->authorizePermission($request, 'groups.view');
        $this->authorizeGroup($request, $group);

        return response()->json(['data' => $group->schedules()->orderBy('day_of_week')->orderBy('starts_at')->get()->map(fn (GroupSchedule $schedule) => $this->schedulePayload($schedule))->values()]);
    }

    public function storeGroupSchedule(Request $request, Group $group)
    {
        $this->authorizePermission($request, 'group-schedules.manage');
        $this->authorizeGroup($request, $group);
        $this->authorizeEditableGroup($group);
        $validated = $this->validateSchedule($request, $group);
        [$startsAt, $endsAt] = ScheduleTimeSlots::times($validated['time_slot']);
        $schedule = $group->schedules()->create([
            ...$validated,
            'ends_at' => $endsAt,
            'is_active' => true,
            'room_name' => null,
            'starts_at' => $startsAt,
        ]);

        return response()->json(['data' => $this->schedulePayload($schedule)], 201);
    }

    public function updateGroupSchedule(Request $request, Group $group, GroupSchedule $schedule)
    {
        $this->authorizePermission($request, 'group-schedules.manage');
        $this->authorizeGroup($request, $group);
        $this->authorizeNestedSchedule($group, $schedule);
        $this->authorizeEditableGroup($group);
        $validated = $this->validateSchedule($request, $group, $schedule);
        [$startsAt, $endsAt] = ScheduleTimeSlots::times($validated['time_slot']);
        $schedule->update([...$validated, 'ends_at' => $endsAt, 'starts_at' => $startsAt]);

        return response()->json(['data' => $this->schedulePayload($schedule->fresh())]);
    }

    public function destroyGroupSchedule(Request $request, Group $group, GroupSchedule $schedule)
    {
        $this->authorizePermission($request, 'group-schedules.manage');
        $this->authorizeGroup($request, $group);
        $this->authorizeNestedSchedule($group, $schedule);
        $this->authorizeEditableGroup($group);
        abort_if($group->schedules()->count() <= 1, 422, __('schedules.errors.required'));
        $schedule->delete();

        return response()->noContent();
    }

    public function studentNotes(Request $request)
    {
        $this->authorizePermission($request, 'student-notes.view');
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'enrollment_id' => ['nullable', 'integer', 'exists:enrollments,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'source' => ['nullable', Rule::in($this->sources())],
            'student_id' => ['nullable', 'integer', 'exists:students,id'],
            'visibility' => ['nullable', Rule::in($this->visibilities())],
        ]);

        return app(AccessScopeService::class)->scopeStudentNotes(StudentNote::query(), $request->user())
            ->with(['author', 'enrollment.group', 'student'])
            ->when($validated['student_id'] ?? null, fn (Builder $query, int $id) => $query->where('student_id', $id))
            ->when($validated['enrollment_id'] ?? null, fn (Builder $query, int $id) => $query->where('enrollment_id', $id))
            ->when($validated['source'] ?? null, fn (Builder $query, string $source) => $query->where('source', $source))
            ->when($validated['visibility'] ?? null, fn (Builder $query, string $visibility) => $query->where('visibility', $visibility))
            ->when($validated['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('noted_at', '>=', $date))
            ->when($validated['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('noted_at', '<=', $date))
            ->latest('noted_at')->latest('id')->paginate($validated['per_page'] ?? 15)
            ->through(fn (StudentNote $note) => $this->notePayload($note));
    }

    public function studentNote(Request $request, StudentNote $studentNote)
    {
        $this->authorizePermission($request, 'student-notes.view');
        $this->authorizeNoteScope($request, $studentNote);

        return response()->json(['data' => $this->notePayload($studentNote->load(['author', 'enrollment.group', 'student']))]);
    }

    public function storeStudentNote(Request $request)
    {
        $this->authorizePermission($request, 'student-notes.create');
        $validated = $this->validateNote($request);
        $this->authorizeNoteRelations($request, $validated);
        $this->normalizeTeacherNote($request, $validated);
        $note = StudentNote::query()->create([...$validated, 'author_id' => $request->user()->id]);

        return response()->json(['data' => $this->notePayload($note->load(['author', 'enrollment.group', 'student']))], 201);
    }

    public function updateStudentNote(Request $request, StudentNote $studentNote)
    {
        $this->authorizePermission($request, 'student-notes.update');
        $this->authorizeNoteScope($request, $studentNote);
        $this->authorizeNoteOwner($request, $studentNote);
        $validated = $this->validateNote($request, $studentNote);
        $this->normalizeTeacherNote($request, $validated);
        $studentNote->update($validated);

        return response()->json(['data' => $this->notePayload($studentNote->fresh()->load(['author', 'enrollment.group', 'student']))]);
    }

    public function destroyStudentNote(Request $request, StudentNote $studentNote)
    {
        $this->authorizePermission($request, 'student-notes.delete');
        $this->authorizeNoteScope($request, $studentNote);
        $this->authorizeNoteOwner($request, $studentNote);
        $studentNote->delete();

        return response()->noContent();
    }

    public function curricula(Request $request)
    {
        $validated = $request->validate([
            'grade_level_id' => ['nullable', 'integer', 'exists:grade_levels,id'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $query = $this->curriculaQuery($request)->with(['course', 'gradeLevel'])->withCount(['groups', 'subjects']);

        return $query
            ->when($validated['search'] ?? null, fn (Builder $builder, string $search) => $builder->where('name', 'like', '%'.$search.'%'))
            ->when(array_key_exists('is_active', $validated), fn (Builder $builder) => $builder->where('is_active', $validated['is_active']))
            ->when($validated['grade_level_id'] ?? null, fn (Builder $builder, int $id) => $builder->where('grade_level_id', $id))
            ->orderBy('name')->paginate($validated['per_page'] ?? 15)
            ->through(fn (Curriculum $curriculum) => $this->curriculumSummaryPayload($curriculum));
    }

    public function curriculum(Request $request, Curriculum $curriculum)
    {
        abort_unless($this->curriculaQuery($request)->whereKey($curriculum->id)->exists(), 403);
        $curriculum->load(['course', 'gradeLevel', 'standaloneResources', 'subjects.definition', 'subjects.resources', 'subjects.lessons.resource', 'subjects.lessons.topics'])->loadCount(['groups', 'subjects']);

        return response()->json(['data' => $this->curriculumPayload($curriculum)]);
    }

    public function groupCurriculum(Request $request, Group $group)
    {
        $access = app(CurriculumAccessService::class);
        abort_unless($access->canView($request->user()), 403);
        abort_unless($access->groupsQuery($request->user())->whereKey($group->id)->exists(), 403);
        $group->load(['course', 'curriculum']);

        return response()->json(['data' => [
            'curriculum' => $group->curriculum ? ['id' => $group->curriculum->id, 'name' => $group->curriculum->name] : null,
            'group' => ['course_name' => $group->course?->name, 'id' => $group->id, 'name' => $group->name],
            'summary' => app(CurriculumProgressService::class)->summary($group),
            'subjects' => app(CurriculumProgressService::class)->subjects($group),
        ]]);
    }

    public function downloadCurriculumResource(Request $request, CurriculumResource $resource)
    {
        abort_unless($resource->pdf_path && Storage::disk('local')->exists($resource->pdf_path), 404);
        $curriculumIds = $resource->curriculumSubjects()->pluck('curriculum_id')->merge($resource->curricula()->pluck('curricula.id'))->unique();
        $access = app(CurriculumAccessService::class);
        abort_unless($access->canManage($request->user()) || $access->groupsQuery($request->user())->whereIn('curriculum_id', $curriculumIds)->exists(), 403);

        return Storage::disk('local')->download($resource->pdf_path, $resource->book_name.'.pdf');
    }

    protected function validateSchedule(Request $request, Group $group, ?GroupSchedule $schedule = null): array
    {
        return $request->validate([
            'day_of_week' => ['required', 'integer', 'between:0,6'],
            'time_slot' => ['required', Rule::in(ScheduleTimeSlots::keys()), Rule::unique('group_schedules', 'time_slot')->where(fn ($query) => $query->where('group_id', $group->id)->where('day_of_week', $request->integer('day_of_week')))->ignore($schedule?->id)],
        ]);
    }

    protected function validateNote(Request $request, ?StudentNote $note = null): array
    {
        return $request->validate([
            'body' => ['required', 'string'],
            'enrollment_id' => [$note ? 'prohibited' : 'nullable', 'integer', 'exists:enrollments,id'],
            'noted_at' => ['required', 'date'],
            'source' => ['required', Rule::in($this->sources())],
            'student_id' => [$note ? 'prohibited' : 'required', 'integer', 'exists:students,id'],
            'visibility' => ['required', Rule::in($this->visibilities())],
        ]);
    }

    protected function authorizeNoteRelations(Request $request, array $validated): void
    {
        $scopes = app(AccessScopeService::class);
        abort_unless($scopes->canAccessStudent($request->user(), Student::query()->findOrFail($validated['student_id'])), 403);
        if ($validated['enrollment_id'] ?? null) {
            $enrollment = Enrollment::query()->findOrFail($validated['enrollment_id']);
            abort_unless($enrollment->student_id === (int) $validated['student_id'] && $scopes->canAccessEnrollment($request->user(), $enrollment), 403);
        }
    }

    protected function normalizeTeacherNote(Request $request, array &$validated): void
    {
        if (! $request->user()->teacherProfile) {
            return;
        }
        abort_unless($validated['source'] === 'teacher' && $validated['visibility'] !== 'private_management', 422);
    }

    protected function curriculaQuery(Request $request): Builder
    {
        $access = app(CurriculumAccessService::class);
        abort_unless($access->canView($request->user()), 403);
        $query = Curriculum::query();

        return $access->canManage($request->user()) ? $query : $query->whereHas('groups', fn (Builder $builder) => $builder->whereIn('groups.id', app(AccessScopeService::class)->accessibleGroupIds($request->user())));
    }

    protected function schedulePayload(GroupSchedule $schedule): array
    {
        return ['day_of_week' => $schedule->day_of_week, 'ends_at' => $schedule->ends_at?->format('H:i'), 'id' => $schedule->id, 'is_active' => $schedule->is_active, 'starts_at' => $schedule->starts_at?->format('H:i'), 'time_slot' => $schedule->time_slot, 'time_slot_label' => ScheduleTimeSlots::options()[$schedule->time_slot] ?? $schedule->time_slot];
    }

    protected function notePayload(StudentNote $note): array
    {
        return ['author' => $note->author ? ['id' => $note->author->id, 'name' => $note->author->name] : null, 'body' => $note->body, 'enrollment_id' => $note->enrollment_id, 'group_name' => $note->enrollment?->group?->name, 'id' => $note->id, 'noted_at' => $note->noted_at?->toIso8601String(), 'source' => $note->source, 'student' => $note->student ? ['id' => $note->student->id, 'name' => $note->student->full_name] : null, 'visibility' => $note->visibility];
    }

    protected function curriculumSummaryPayload(Curriculum $curriculum): array
    {
        return ['course' => $curriculum->course ? ['id' => $curriculum->course->id, 'name' => $curriculum->course->name] : null, 'grade_level' => $curriculum->gradeLevel ? ['id' => $curriculum->gradeLevel->id, 'name' => $curriculum->gradeLevel->name] : null, 'groups_count' => $curriculum->groups_count, 'id' => $curriculum->id, 'is_active' => $curriculum->is_active, 'name' => $curriculum->name, 'subjects_count' => $curriculum->subjects_count];
    }

    protected function curriculumPayload(Curriculum $curriculum): array
    {
        return [...$this->curriculumSummaryPayload($curriculum), 'standalone_resources' => $curriculum->standaloneResources->map(fn ($resource) => $this->resourcePayload($resource))->values(), 'subjects' => $curriculum->subjects->map(fn ($subject) => ['id' => $subject->id, 'name' => $subject->definition?->name, 'resources' => $subject->resources->map(fn ($resource) => $this->resourcePayload($resource))->values(), 'lessons' => $subject->lessons->map(fn ($lesson) => ['chapter_number' => $lesson->chapter_number, 'id' => $lesson->id, 'importance' => $lesson->importance, 'name' => $lesson->name, 'page_count' => $lesson->page_count, 'resource' => $lesson->resource ? $this->resourcePayload($lesson->resource) : null, 'topics' => $lesson->topics->map(fn ($topic) => ['id' => $topic->id, 'name' => $topic->name])->values()])->values()])->values()];
    }

    protected function resourcePayload(CurriculumResource $resource): array
    {
        return ['author' => $resource->author, 'book_name' => $resource->book_name, 'download_endpoint' => $resource->pdf_path ? '/api/v1/curriculum-resources/'.$resource->id.'/download' : null, 'edition_number' => $resource->edition_number, 'id' => $resource->id, 'publisher' => $resource->publisher, 'published_on' => $resource->published_on?->format('Y-m-d')];
    }

    protected function authorizeGroup(Request $request, Group $group): void
    {
        abort_unless(app(AccessScopeService::class)->canAccessGroup($request->user(), $group), 403);
    }

    protected function authorizeEditableGroup(Group $group): void
    {
        $group->loadMissing('course');
        abort_if($group->course_finished_at || ! ($group->course?->is_active ?? true), 422, __('crud.groups.errors.course_archived'));
    }

    protected function authorizeNestedSchedule(Group $group, GroupSchedule $schedule): void
    {
        abort_unless($schedule->group_id === $group->id, 404);
    }

    protected function authorizeNoteScope(Request $request, StudentNote $note): void
    {
        abort_unless(app(AccessScopeService::class)->scopeStudentNotes(StudentNote::query()->whereKey($note->id), $request->user())->exists(), 403);
    }

    protected function authorizeNoteOwner(Request $request, StudentNote $note): void
    {
        abort_unless(app(AccessScopeService::class)->isUnrestricted($request->user()) || $note->author_id === $request->user()->id, 403);
    }

    protected function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->can($permission), 403);
    }

    protected function sources(): array
    {
        return ['management', 'teacher', 'parent', 'system'];
    }

    protected function visibilities(): array
    {
        return ['private_teacher', 'private_management', 'shared_internal', 'visible_to_parent'];
    }
}
