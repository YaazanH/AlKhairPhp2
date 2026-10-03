<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Group;
use App\Models\MemorizationSession;
use App\Models\QuranTest;
use App\Models\Student;
use App\Models\StudentAttendanceRecord;
use App\Models\User;
use App\Services\Landlord\CurrentModuleAccess;
use Illuminate\Database\Eloquent\Builder;

class ReportDesignerQueryService
{
    public const PREVIEW_LIMIT = 25;

    public function __construct(
        protected AccessScopeService $accessScopes,
        protected ReportDesignerCatalog $catalog,
        protected CurrentModuleAccess $modules,
    ) {}

    public function preview(array $definition, ?User $user): array
    {
        $source = (string) ($definition['data_source'] ?? '');
        abort_unless(array_key_exists($source, $this->catalog->sources()), 403);
        $fields = $this->catalog->validateFields($source, (array) ($definition['selected_fields'] ?? []));
        [$sortField, $sortDirection] = $this->catalog->validateSort(
            $source,
            $definition['sort_field'] ?? null,
            (string) ($definition['sort_direction'] ?? 'asc'),
        );

        return match ($source) {
            ReportDesignerCatalog::STUDENTS => $this->studentPreview(
                $fields,
                (array) ($definition['filters'] ?? []),
                $sortField,
                $sortDirection,
                $user,
            ),
            ReportDesignerCatalog::COURSES => $this->coursePreview(
                $fields,
                (array) ($definition['filters'] ?? []),
                $sortField,
                $sortDirection,
                $user,
            ),
            ReportDesignerCatalog::GROUPS => $this->groupPreview(
                $fields,
                (array) ($definition['filters'] ?? []),
                $sortField,
                $sortDirection,
                $user,
            ),
            ReportDesignerCatalog::STUDENT_ATTENDANCE => $this->studentAttendancePreview(
                $fields,
                (array) ($definition['filters'] ?? []),
                $sortField,
                $sortDirection,
                $user,
            ),
            ReportDesignerCatalog::MEMORIZATION_SESSIONS => $this->memorizationPreview(
                $fields,
                (array) ($definition['filters'] ?? []),
                $sortField,
                $sortDirection,
                $user,
            ),
            ReportDesignerCatalog::QURAN_TESTS => $this->quranTestPreview(
                $fields,
                (array) ($definition['filters'] ?? []),
                $sortField,
                $sortDirection,
                $user,
            ),
        };
    }

    protected function studentPreview(array $fields, array $filters, ?string $sortField, string $sortDirection, ?User $user): array
    {
        $query = $this->accessScopes->scopeStudents(
            Student::query()->with([
                'gradeLevel:id,name',
                'enrollments' => fn ($query) => $query
                    ->where('status', 'active')
                    ->with('group:id,name')
                    ->orderByDesc('enrolled_at')
                    ->orderByDesc('id'),
            ]),
            $user,
        );

        $status = (string) ($filters['status'] ?? 'all');
        if (in_array($status, ['active', 'inactive'], true)) {
            $query->where('status', $status);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->where('student_number', 'like', '%'.$search.'%')
                    ->orWhere('first_name', 'like', '%'.$search.'%')
                    ->orWhere('last_name', 'like', '%'.$search.'%');
            });
        }

        $joinedFrom = (string) ($filters['date_from'] ?? $filters['joined_from'] ?? '');
        $joinedTo = (string) ($filters['date_to'] ?? $filters['joined_to'] ?? '');
        $query->when($joinedFrom !== '', fn (Builder $builder) => $builder->whereDate('joined_at', '>=', $joinedFrom));
        $query->when($joinedTo !== '', fn (Builder $builder) => $builder->whereDate('joined_at', '<=', $joinedTo));

        $total = (clone $query)->count();
        $this->applyStudentSort($query, $sortField, $sortDirection);

        $rows = $query->limit(self::PREVIEW_LIMIT)->get()->map(function (Student $student) use ($fields): array {
            return collect($fields)->mapWithKeys(fn (string $field) => [
                $field => $this->studentValue($student, $field),
            ])->all();
        })->all();

        return [
            'columns' => collect($this->catalog->fields(ReportDesignerCatalog::STUDENTS))->only($fields)->all(),
            'rows' => $rows,
            'total' => $total,
            'limit' => self::PREVIEW_LIMIT,
        ];
    }

    protected function coursePreview(array $fields, array $filters, ?string $sortField, string $sortDirection, ?User $user): array
    {
        $groupIds = $this->accessScopes->isUnrestricted($user)
            ? null
            : $this->accessScopes->accessibleGroupIds($user);
        $scopeGroups = static function (Builder $builder) use ($groupIds): void {
            $builder->when($groupIds !== null, fn (Builder $query) => $query->whereIn('groups.id', $groupIds));
        };

        $query = Course::query()
            ->with('academicYear:id,name')
            ->withCount([
                'groups' => $scopeGroups,
                'groups as active_groups_count' => function (Builder $builder) use ($scopeGroups): void {
                    $scopeGroups($builder);
                    $builder->where('is_active', true);
                },
                'groups as active_enrollments_count' => function (Builder $builder) use ($scopeGroups): void {
                    $scopeGroups($builder);
                    $builder
                        ->join('enrollments', 'groups.id', '=', 'enrollments.group_id')
                        ->where('enrollments.status', 'active')
                        ->whereNull('enrollments.deleted_at');
                },
            ]);

        if ($groupIds !== null) {
            $query->whereHas('groups', fn (Builder $builder) => $builder->whereIn('groups.id', $groupIds));
        }

        $this->applyCommonFilters($query, $filters, ['name'], 'starts_on');
        $total = (clone $query)->count();
        $this->applyCourseSort($query, $sortField, $sortDirection);

        $rows = $query->limit(self::PREVIEW_LIMIT)->get()->map(function (Course $course) use ($fields): array {
            return collect($fields)->mapWithKeys(fn (string $field) => [
                $field => $this->courseValue($course, $field),
            ])->all();
        })->all();

        return $this->result(ReportDesignerCatalog::COURSES, $fields, $rows, $total);
    }

    protected function groupPreview(array $fields, array $filters, ?string $sortField, string $sortDirection, ?User $user): array
    {
        $query = $this->accessScopes->scopeGroups(
            Group::query()
                ->with(['course:id,name', 'academicYear:id,name', 'teacher:id,first_name,last_name', 'assistantTeacher:id,first_name,last_name', 'gradeLevel:id,name'])
                ->withCount(['enrollments as active_enrollments_count' => fn (Builder $builder) => $builder->where('status', 'active')]),
            $user,
        );

        $this->applyCommonFilters($query, $filters, ['name'], 'starts_on', function (Builder $builder, string $search): void {
            $builder
                ->orWhereHas('course', fn (Builder $relation) => $relation->where('name', 'like', '%'.$search.'%'))
                ->orWhereHas('teacher', fn (Builder $relation) => $relation
                    ->where('first_name', 'like', '%'.$search.'%')
                    ->orWhere('last_name', 'like', '%'.$search.'%'));
        });
        $total = (clone $query)->count();
        $this->applyGroupSort($query, $sortField, $sortDirection);

        $rows = $query->limit(self::PREVIEW_LIMIT)->get()->map(function (Group $group) use ($fields): array {
            return collect($fields)->mapWithKeys(fn (string $field) => [
                $field => $this->groupValue($group, $field),
            ])->all();
        })->all();

        return $this->result(ReportDesignerCatalog::GROUPS, $fields, $rows, $total);
    }

    protected function studentAttendancePreview(array $fields, array $filters, ?string $sortField, string $sortDirection, ?User $user): array
    {
        $query = $this->accessScopes->scopeStudentAttendanceRecords(
            StudentAttendanceRecord::query()->with([
                'status:id,name,is_present',
                'student:id,student_number,first_name,last_name',
                'studentAttendanceDay.course',
                'attendanceDay.group.course',
                'enrollment.student:id,student_number,first_name,last_name',
                'enrollment.group.course',
            ]),
            $user,
        );

        if (! $this->modules->enabled('classes')) {
            $query->whereNotNull('student_attendance_day_id');
        }

        $status = (string) ($filters['status'] ?? 'all');
        if (in_array($status, ['present', 'not_present'], true)) {
            $query->whereHas('status', fn (Builder $builder) => $builder->where('is_present', $status === 'present'));
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->whereHas('student', fn (Builder $student) => $this->applyStudentSearch($student, $search))
                    ->orWhereHas('enrollment.student', fn (Builder $student) => $this->applyStudentSearch($student, $search));
            });
        }

        $dateFrom = (string) ($filters['date_from'] ?? '');
        $dateTo = (string) ($filters['date_to'] ?? '');
        if ($dateFrom !== '' || $dateTo !== '') {
            $query->where(function (Builder $builder) use ($dateFrom, $dateTo): void {
                $builder
                    ->whereHas('studentAttendanceDay', fn (Builder $day) => $this->applyDateBounds($day, 'attendance_date', $dateFrom, $dateTo))
                    ->orWhereHas('attendanceDay', fn (Builder $day) => $this->applyDateBounds($day, 'attendance_date', $dateFrom, $dateTo));
            });
        }

        $total = (clone $query)->count();
        $query->orderByDesc('id');

        $rows = $query->limit(self::PREVIEW_LIMIT)->get()->map(function (StudentAttendanceRecord $record) use ($fields): array {
            return collect($fields)->mapWithKeys(fn (string $field) => [
                $field => $this->studentAttendanceValue($record, $field),
            ])->all();
        })->all();

        return $this->result(ReportDesignerCatalog::STUDENT_ATTENDANCE, $fields, $rows, $total);
    }

    protected function memorizationPreview(array $fields, array $filters, ?string $sortField, string $sortDirection, ?User $user): array
    {
        $query = $this->accessScopes->scopeMemorizationSessions(
            MemorizationSession::query()->with([
                'student:id,student_number,first_name,last_name',
                'teacher:id,first_name,last_name',
                'enrollment.group.course',
            ]),
            $user,
        );

        $entryType = (string) ($filters['status'] ?? 'all');
        if (in_array($entryType, ['new', 'review', 'correction'], true)) {
            $query->where('entry_type', $entryType);
        }

        $this->applyRelatedStudentSearch($query, $filters);
        $this->applyDateBounds(
            $query,
            'recorded_on',
            (string) ($filters['date_from'] ?? ''),
            (string) ($filters['date_to'] ?? ''),
        );

        $total = (clone $query)->count();
        $this->applyMemorizationSort($query, $sortField, $sortDirection);

        $rows = $query->limit(self::PREVIEW_LIMIT)->get()->map(function (MemorizationSession $session) use ($fields): array {
            return collect($fields)->mapWithKeys(fn (string $field) => [
                $field => $this->memorizationValue($session, $field),
            ])->all();
        })->all();

        return $this->result(ReportDesignerCatalog::MEMORIZATION_SESSIONS, $fields, $rows, $total);
    }

    protected function quranTestPreview(array $fields, array $filters, ?string $sortField, string $sortDirection, ?User $user): array
    {
        $query = $this->accessScopes->scopeQuranTests(
            QuranTest::query()->with([
                'student:id,student_number,first_name,last_name',
                'teacher:id,first_name,last_name',
                'type:id,name,code',
                'juz:id,juz_number',
                'enrollment.group.course',
            ]),
            $user,
        );

        $status = (string) ($filters['status'] ?? 'all');
        if (in_array($status, ['passed', 'failed', 'cancelled'], true)) {
            $query->where('status', $status);
        }

        $this->applyRelatedStudentSearch($query, $filters);
        $this->applyDateBounds(
            $query,
            'tested_on',
            (string) ($filters['date_from'] ?? ''),
            (string) ($filters['date_to'] ?? ''),
        );

        $total = (clone $query)->count();
        $this->applyQuranTestSort($query, $sortField, $sortDirection);

        $rows = $query->limit(self::PREVIEW_LIMIT)->get()->map(function (QuranTest $test) use ($fields): array {
            return collect($fields)->mapWithKeys(fn (string $field) => [
                $field => $this->quranTestValue($test, $field),
            ])->all();
        })->all();

        return $this->result(ReportDesignerCatalog::QURAN_TESTS, $fields, $rows, $total);
    }

    protected function applyRelatedStudentSearch(Builder $query, array $filters): void
    {
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->whereHas('student', fn (Builder $student) => $this->applyStudentSearch($student, $search));
        }
    }

    protected function applyStudentSearch(Builder $query, string $search): void
    {
        $query->where(function (Builder $builder) use ($search): void {
            $builder
                ->where('student_number', 'like', '%'.$search.'%')
                ->orWhere('first_name', 'like', '%'.$search.'%')
                ->orWhere('last_name', 'like', '%'.$search.'%');
        });
    }

    protected function applyDateBounds(Builder $query, string $column, string $dateFrom, string $dateTo): void
    {
        $query->when($dateFrom !== '', fn (Builder $builder) => $builder->whereDate($column, '>=', $dateFrom));
        $query->when($dateTo !== '', fn (Builder $builder) => $builder->whereDate($column, '<=', $dateTo));
    }

    protected function applyCommonFilters(Builder $query, array $filters, array $searchColumns, string $dateColumn, ?callable $extendSearch = null): void
    {
        $status = (string) ($filters['status'] ?? 'all');
        if (in_array($status, ['active', 'inactive'], true)) {
            $query->where('is_active', $status === 'active');
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($extendSearch, $search, $searchColumns): void {
                foreach ($searchColumns as $index => $column) {
                    $builder->{$index === 0 ? 'where' : 'orWhere'}($column, 'like', '%'.$search.'%');
                }

                $extendSearch?->__invoke($builder, $search);
            });
        }

        $dateFrom = (string) ($filters['date_from'] ?? '');
        $dateTo = (string) ($filters['date_to'] ?? '');
        $query->when($dateFrom !== '', fn (Builder $builder) => $builder->whereDate($dateColumn, '>=', $dateFrom));
        $query->when($dateTo !== '', fn (Builder $builder) => $builder->whereDate($dateColumn, '<=', $dateTo));
    }

    protected function applyCourseSort(Builder $query, ?string $field, string $direction): void
    {
        match ($field) {
            'course_name' => $query->orderBy('name', $direction),
            'status' => $query->orderBy('is_active', $direction),
            'starts_on', 'ends_on', 'groups_count', 'active_groups_count', 'active_enrollments_count' => $query->orderBy($field, $direction),
            default => $query->orderByDesc('id'),
        };
    }

    protected function applyGroupSort(Builder $query, ?string $field, string $direction): void
    {
        match ($field) {
            'group_name' => $query->orderBy('name', $direction),
            'status' => $query->orderBy('is_active', $direction),
            'starts_on', 'ends_on', 'capacity', 'active_enrollments_count' => $query->orderBy($field, $direction),
            default => $query->orderByDesc('id'),
        };
    }

    protected function applyMemorizationSort(Builder $query, ?string $field, string $direction): void
    {
        match ($field) {
            'recorded_on', 'entry_type', 'from_page', 'to_page', 'pages_count' => $query->orderBy($field, $direction),
            default => $query->orderByDesc('recorded_on')->orderByDesc('id'),
        };
    }

    protected function applyQuranTestSort(Builder $query, ?string $field, string $direction): void
    {
        match ($field) {
            'tested_on' => $query->orderBy('tested_on', $direction),
            'test_status' => $query->orderBy('status', $direction),
            'score' => $query->orderBy('score', $direction),
            'attempt_number' => $query->orderBy('attempt_no', $direction),
            default => $query->orderByDesc('tested_on')->orderByDesc('id'),
        };
    }

    protected function courseValue(Course $course, string $field): mixed
    {
        return match ($field) {
            'course_name' => $course->name,
            'academic_year' => $course->academicYear?->name,
            'status' => __('report_designer.record_statuses.'.($course->is_active ? 'active' : 'inactive')),
            'starts_on' => $course->starts_on?->format('Y-m-d'),
            'ends_on' => $course->ends_on?->format('Y-m-d'),
            'groups_count' => $course->groups_count,
            'active_groups_count' => $course->active_groups_count,
            'active_enrollments_count' => $course->active_enrollments_count,
        };
    }

    protected function groupValue(Group $group, string $field): mixed
    {
        return match ($field) {
            'group_name' => $group->name,
            'course_name' => $group->course?->name,
            'academic_year' => $group->academicYear?->name,
            'teacher_name' => $this->personName($group->teacher),
            'assistant_teacher_name' => $this->personName($group->assistantTeacher),
            'grade_level' => $group->gradeLevel?->name,
            'capacity' => $group->capacity,
            'active_enrollments_count' => $group->active_enrollments_count,
            'available_places' => max(0, (int) $group->capacity - (int) $group->active_enrollments_count),
            'status' => __('report_designer.record_statuses.'.($group->is_active ? 'active' : 'inactive')),
            'starts_on' => $group->starts_on?->format('Y-m-d'),
            'ends_on' => $group->ends_on?->format('Y-m-d'),
        };
    }

    protected function studentAttendanceValue(StudentAttendanceRecord $record, string $field): mixed
    {
        $student = $record->student ?? $record->enrollment?->student;
        $group = $record->attendanceDay?->group ?? $record->enrollment?->group;
        $course = $record->studentAttendanceDay?->course ?? $group?->course;

        return match ($field) {
            'attendance_date' => ($record->studentAttendanceDay?->attendance_date ?? $record->attendanceDay?->attendance_date)?->format('Y-m-d'),
            'student_number' => $student?->student_number,
            'full_name' => $this->personName($student),
            'attendance_status' => $record->status?->name,
            'presence_result' => $record->status
                ? __('report_designer.presence_results.'.($record->status->is_present ? 'present' : 'not_present'))
                : __('report_designer.presence_results.unknown'),
            'attendance_scope' => __('report_designer.attendance_scopes.'.($record->student_attendance_day_id ? 'center' : 'groups')),
            'course_name' => $course?->name,
            'group_name' => $group?->name,
            'notes' => $record->notes,
        };
    }

    protected function memorizationValue(MemorizationSession $session, string $field): mixed
    {
        return match ($field) {
            'recorded_on' => $session->recorded_on?->format('Y-m-d'),
            'student_number' => $session->student?->student_number,
            'full_name' => $this->personName($session->student),
            'entry_type' => __('report_designer.entry_types.'.$session->entry_type),
            'from_page' => $session->from_page,
            'to_page' => $session->to_page,
            'pages_count' => $session->pages_count,
            'teacher_name' => $this->personName($session->teacher),
            'course_name' => $session->enrollment?->group?->course?->name,
            'group_name' => $session->enrollment?->group?->name,
            'notes' => $session->notes,
        };
    }

    protected function quranTestValue(QuranTest $test, string $field): mixed
    {
        return match ($field) {
            'tested_on' => $test->tested_on?->format('Y-m-d'),
            'student_number' => $test->student?->student_number,
            'full_name' => $this->personName($test->student),
            'test_type' => $test->type?->name,
            'juz_number' => $test->juz?->juz_number,
            'test_status' => __('report_designer.test_statuses.'.$test->status),
            'score' => $test->score !== null ? (float) $test->score : null,
            'attempt_number' => $test->attempt_no,
            'teacher_name' => $this->personName($test->teacher),
            'course_name' => $test->enrollment?->group?->course?->name,
            'group_name' => $test->enrollment?->group?->name,
            'notes' => $test->notes,
        };
    }

    protected function personName(mixed $person): ?string
    {
        return $person ? trim($person->first_name.' '.$person->last_name) : null;
    }

    protected function result(string $source, array $fields, array $rows, int $total): array
    {
        return [
            'columns' => collect($this->catalog->fields($source))->only($fields)->all(),
            'rows' => $rows,
            'total' => $total,
            'limit' => self::PREVIEW_LIMIT,
        ];
    }

    protected function applyStudentSort(Builder $query, ?string $field, string $direction): void
    {
        match ($field) {
            'full_name' => $query->orderBy('first_name', $direction)->orderBy('last_name', $direction),
            'student_number', 'status', 'joined_at', 'birth_date' => $query->orderBy($field, $direction),
            default => $query->orderByDesc('id'),
        };
    }

    protected function studentValue(Student $student, string $field): mixed
    {
        return match ($field) {
            'student_number' => $student->student_number,
            'full_name' => trim($student->first_name.' '.$student->last_name),
            'status' => __('report_designer.record_statuses.'.$student->status),
            'joined_at' => $student->joined_at?->format('Y-m-d'),
            'birth_date' => $student->birth_date?->format('Y-m-d'),
            'grade_level' => $student->gradeLevel?->name,
            'current_group' => $student->currentActiveEnrollment()?->group?->name,
        };
    }
}
