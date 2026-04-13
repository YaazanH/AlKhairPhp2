<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Enrollment;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;

class ActivityAudienceService
{
    public const SCOPE_SINGLE_GROUP = 'single_group';
    public const SCOPE_MULTIPLE_GROUPS = 'multiple_groups';
    public const SCOPE_ALL_GROUPS = 'all_groups';

    public function scopes(): array
    {
        return [
            self::SCOPE_SINGLE_GROUP,
            self::SCOPE_MULTIPLE_GROUPS,
            self::SCOPE_ALL_GROUPS,
        ];
    }

    public function isAllGroups(Activity $activity): bool
    {
        return ($activity->audience_scope ?: self::SCOPE_ALL_GROUPS) === self::SCOPE_ALL_GROUPS;
    }

    public function targetedGroupIds(Activity $activity): array
    {
        if ($this->isAllGroups($activity)) {
            return [];
        }

        $activity->loadMissing('targetGroups');

        $ids = $activity->targetGroups
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($ids === [] && $activity->group_id) {
            $ids[] = (int) $activity->group_id;
        }

        return collect($ids)
            ->filter(fn ($id) => filled($id))
            ->unique()
            ->values()
            ->all();
    }

    public function eligibleEnrollmentQuery(Activity $activity): Builder
    {
        $query = Enrollment::query()
            ->with(['student', 'group'])
            ->where('status', 'active');

        if (! $this->isAllGroups($activity)) {
            $groupIds = $this->targetedGroupIds($activity);

            if ($groupIds === []) {
                return $query->whereRaw('1 = 0');
            }

            $query->whereIn('group_id', $groupIds);
        }

        return $query;
    }

    public function eligibleStudentIds(Activity $activity): array
    {
        return $this->eligibleEnrollmentQuery($activity)
            ->pluck('student_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function enrollmentMatches(Activity $activity, Enrollment $enrollment): bool
    {
        if ($enrollment->status !== 'active') {
            return false;
        }

        return $this->isAllGroups($activity)
            || in_array((int) $enrollment->group_id, $this->targetedGroupIds($activity), true);
    }

    public function resolveEnrollmentForStudent(Activity $activity, Student|int $student): ?Enrollment
    {
        $studentModel = $student instanceof Student
            ? $student
            : Student::query()->find($student);

        if (! $studentModel) {
            return null;
        }

        if ($studentModel->relationLoaded('enrollments')) {
            return $studentModel->enrollments
                ->filter(fn (Enrollment $enrollment) => $this->enrollmentMatches($activity, $enrollment))
                ->sortByDesc(fn (Enrollment $enrollment): int => (($enrollment->enrolled_at?->getTimestamp() ?? 0) * 1000000) + $enrollment->id)
                ->first();
        }

        return $this->eligibleEnrollmentQuery($activity)
            ->where('student_id', $studentModel->id)
            ->orderByDesc('enrolled_at')
            ->orderByDesc('id')
            ->first();
    }

    public function syncTargets(Activity $activity, string $audienceScope, ?int $singleGroupId, array $groupIds = []): void
    {
        $normalizedScope = in_array($audienceScope, $this->scopes(), true)
            ? $audienceScope
            : self::SCOPE_ALL_GROUPS;

        $normalizedGroupIds = collect($groupIds)
            ->filter(fn ($id) => filled($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($normalizedScope === self::SCOPE_SINGLE_GROUP) {
            $singleGroupId = $singleGroupId ? (int) $singleGroupId : null;
            $normalizedGroupIds = $singleGroupId ? [$singleGroupId] : [];
        }

        if ($normalizedScope === self::SCOPE_ALL_GROUPS) {
            $activity->forceFill([
                'audience_scope' => self::SCOPE_ALL_GROUPS,
                'group_id' => null,
            ])->saveQuietly();

            $activity->targetGroups()->sync([]);

            return;
        }

        $activity->forceFill([
            'audience_scope' => $normalizedScope,
            'group_id' => $normalizedScope === self::SCOPE_SINGLE_GROUP ? ($normalizedGroupIds[0] ?? null) : null,
        ])->saveQuietly();

        $activity->targetGroups()->sync($normalizedGroupIds);
    }
}
