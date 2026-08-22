<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Student;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Shared response shapes for the mobile API.
 *
 * Extracted from ParentMobileController so role-aware controllers emit byte
 * identical payloads — the mobile app parses one set of models regardless of
 * which endpoint family served the record.
 */
trait PresentsMobileRecords
{
    protected function dateFilters(Request $request, array $extraRules = []): array
    {
        return $request->validate(array_merge([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ], $extraRules)) + ['per_page' => 25];
    }

    protected function studentSummary(Student $student): array
    {
        return [
            'id' => $student->id,
            'student_number' => $student->student_number,
            'full_name' => $student->full_name,
            'first_name' => $student->first_name,
            'last_name' => $student->last_name,
            'status' => $student->status,
            'birth_date' => $this->date($student->birth_date),
            'gender' => $student->gender,
            'grade_level' => [
                'id' => $student->gradeLevel?->id,
                'name' => $student->gradeLevel?->name,
            ],
            'quran_current_juz' => [
                'id' => $student->quranCurrentJuz?->id,
                'juz_number' => $student->quranCurrentJuz?->juz_number,
            ],
        ];
    }

    protected function studentDetail(Student $student): array
    {
        $activeEnrollments = $student->enrollments
            ->where('status', 'active')
            ->sortByDesc(fn (Enrollment $enrollment): int => (($enrollment->enrolled_at?->getTimestamp() ?? 0) * 1000000) + $enrollment->id)
            ->values();

        return $this->studentSummary($student) + [
            'school_name' => $student->school_name,
            'joined_at' => $this->date($student->joined_at),
            'memorized_pages' => (int) $activeEnrollments->sum('memorized_pages_cached'),
            'points' => (int) $activeEnrollments->sum('final_points_cached'),
            'active_enrollments' => $activeEnrollments
                ->map(fn (Enrollment $enrollment): array => $this->enrollmentSummary($enrollment))
                ->values(),
        ];
    }

    protected function enrollmentSummary(Enrollment $enrollment): array
    {
        return [
            'id' => $enrollment->id,
            'status' => $enrollment->status,
            'enrolled_at' => $this->date($enrollment->enrolled_at),
            'left_at' => $this->date($enrollment->left_at),
            'memorized_pages' => $enrollment->memorized_pages_cached,
            'points' => $enrollment->final_points_cached,
            'group' => $this->groupSummary($enrollment->group),
        ];
    }

    protected function groupSummary(?Group $group): ?array
    {
        if (! $group) {
            return null;
        }

        return [
            'id' => $group->id,
            'name' => $group->name,
            'course' => [
                'id' => $group->course?->id,
                'name' => $group->course?->name,
            ],
            'teacher' => $this->teacherSummary($group->teacher),
        ];
    }

    protected function teacherSummary($teacher): ?array
    {
        if (! $teacher) {
            return null;
        }

        return [
            'id' => $teacher->id,
            'full_name' => trim($teacher->first_name.' '.$teacher->last_name),
            'phone' => $teacher->phone,
        ];
    }

    protected function juzSummary($juz): ?array
    {
        if (! $juz) {
            return null;
        }

        return [
            'id' => $juz->id,
            'juz_number' => $juz->juz_number,
            'from_page' => $juz->from_page,
            'to_page' => $juz->to_page,
        ];
    }

    protected function paginated(LengthAwarePaginator $paginator, callable $map): array
    {
        return [
            'data' => $paginator->getCollection()->map($map)->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    protected function paginatedCollection(Collection $items, int $perPage): array
    {
        $page = max((int) request('page', 1), 1);
        $total = $items->count();

        return [
            'data' => $items->forPage($page, $perPage)->values(),
            'meta' => [
                'current_page' => $page,
                'last_page' => (int) max(ceil($total / $perPage), 1),
                'per_page' => $perPage,
                'total' => $total,
            ],
        ];
    }

    protected function withinDateRange(?string $date, array $filters): bool
    {
        if (! $date) {
            return true;
        }

        $value = Carbon::parse($date);

        if (($filters['date_from'] ?? null) && $value->lt(Carbon::parse($filters['date_from']))) {
            return false;
        }

        if (($filters['date_to'] ?? null) && $value->gt(Carbon::parse($filters['date_to']))) {
            return false;
        }

        return true;
    }

    protected function date($value): ?string
    {
        return $value ? Carbon::parse($value)->toDateString() : null;
    }

    protected function datetime($value): ?string
    {
        return $value ? Carbon::parse($value)->toISOString() : null;
    }

    protected function decimal($value): ?float
    {
        return $value === null ? null : round((float) $value, 2);
    }
}
