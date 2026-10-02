<?php

namespace App\Services;

use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ReportDesignerQueryService
{
    public const PREVIEW_LIMIT = 25;

    public function __construct(
        protected AccessScopeService $accessScopes,
        protected ReportDesignerCatalog $catalog,
    ) {}

    public function preview(array $definition, ?User $user): array
    {
        $source = (string) ($definition['data_source'] ?? '');
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

        $joinedFrom = (string) ($filters['joined_from'] ?? '');
        $joinedTo = (string) ($filters['joined_to'] ?? '');
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
            'status' => __('report_designer.student_statuses.'.$student->status),
            'joined_at' => $student->joined_at?->format('Y-m-d'),
            'birth_date' => $student->birth_date?->format('Y-m-d'),
            'grade_level' => $student->gradeLevel?->name,
            'current_group' => $student->currentActiveEnrollment()?->group?->name,
        };
    }
}
