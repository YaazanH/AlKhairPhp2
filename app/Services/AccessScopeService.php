<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ActivityExpense;
use App\Models\ActivityPayment;
use App\Models\ActivityRegistration;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\GroupAttendanceDay;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\MemorizationSession;
use App\Models\ParentProfile;
use App\Models\Payment;
use App\Models\PointTransaction;
use App\Models\Student;
use App\Models\StudentAttendanceRecord;
use App\Models\StudentAttendanceDay;
use App\Models\StudentNote;
use App\Models\Teacher;
use App\Models\TeacherAttendanceRecord;
use App\Models\User;
use App\Models\UserScopeOverride;
use App\Support\RoleRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

class AccessScopeService
{
    protected array $memoizedIds = [];

    protected array $memoizedUserRelations = [];

    public function syncUserOverrides(User $user, array $scopes, ?int $assignedBy = null): void
    {
        $rows = collect($scopes)
            ->flatMap(function (array $ids, string $type) use ($assignedBy, $user) {
                return collect($ids)
                    ->filter(fn ($id) => filled($id))
                    ->map(fn ($id) => [
                        'assigned_by' => $assignedBy,
                        'created_at' => now(),
                        'scope_id' => (int) $id,
                        'scope_type' => $type,
                        'updated_at' => now(),
                        'user_id' => $user->id,
                    ]);
            })
            ->unique(fn (array $row) => $row['scope_type'].'-'.$row['scope_id'])
            ->values();

        UserScopeOverride::query()->where('user_id', $user->id)->delete();

        if ($rows->isNotEmpty()) {
            UserScopeOverride::query()->insert($rows->all());
        }

        unset(
            $this->memoizedIds["groups.{$user->id}"],
            $this->memoizedIds["students.{$user->id}"],
            $this->memoizedIds["enrollments.{$user->id}"],
            $this->memoizedIds["teachers.{$user->id}"],
            $this->memoizedIds["parents.{$user->id}"],
        );
    }

    public function canAccessAssessment(?User $user, Assessment $assessment): bool
    {
        if ($this->isUnrestricted($user)) {
            return true;
        }

        $groupIds = $this->accessibleGroupIds($user);

        if ($assessment->group_id !== null && in_array((int) $assessment->group_id, $groupIds, true)) {
            return true;
        }

        return $groupIds !== []
            && $assessment->groups()->whereIn('groups.id', $groupIds)->exists();
    }

    public function canAccessEnrollment(?User $user, Enrollment $enrollment): bool
    {
        if ($this->isUnrestricted($user)) {
            return true;
        }

        return in_array((int) $enrollment->id, $this->accessibleEnrollmentIds($user), true);
    }

    public function canAccessGroup(?User $user, Group $group): bool
    {
        if ($this->isUnrestricted($user)) {
            return true;
        }

        return in_array((int) $group->id, $this->accessibleGroupIds($user), true);
    }

    public function canAccessGroupAttendanceDay(?User $user, GroupAttendanceDay $groupAttendanceDay): bool
    {
        if ($this->isUnrestricted($user)) {
            return true;
        }

        return $groupAttendanceDay->group_id !== null
            && in_array((int) $groupAttendanceDay->group_id, $this->accessibleGroupIds($user), true);
    }

    public function canAccessInvoice(?User $user, Invoice $invoice): bool
    {
        if ($this->isUnrestricted($user)) {
            return true;
        }

        if ($invoice->parent_id && in_array((int) $invoice->parent_id, $this->accessibleParentIds($user), true)) {
            return true;
        }

        $studentIds = $this->accessibleStudentIds($user);

        if ($studentIds === []) {
            return false;
        }

        return $invoice->items()
            ->where(function (Builder $query) use ($studentIds) {
                $query
                    ->whereIn('student_id', $studentIds)
                    ->orWhereHas('enrollment', fn (Builder $builder) => $builder->whereIn('student_id', $studentIds));
            })
            ->exists();
    }

    public function canAccessParent(?User $user, ParentProfile $parentProfile): bool
    {
        if ($this->isUnrestricted($user)) {
            return true;
        }

        return in_array((int) $parentProfile->id, $this->accessibleParentIds($user), true);
    }

    public function canAccessStudent(?User $user, Student $student): bool
    {
        if ($this->isUnrestricted($user)) {
            return true;
        }

        return in_array((int) $student->id, $this->accessibleStudentIds($user), true);
    }

    public function canAccessStudentAttendanceDay(?User $user, StudentAttendanceDay $studentAttendanceDay): bool
    {
        if ($this->isUnrestricted($user)) {
            return true;
        }

        $groupIds = $this->accessibleGroupIds($user);

        if ($groupIds === []) {
            return false;
        }

        return $studentAttendanceDay->groupAttendanceDays()
            ->whereIn('group_id', $groupIds)
            ->exists();
    }

    public function canAccessTeacher(?User $user, Teacher $teacher): bool
    {
        if ($this->isUnrestricted($user)) {
            return true;
        }

        return in_array((int) $teacher->id, $this->accessibleTeacherIds($user), true);
    }

    public function accessibleEnrollmentIds(?User $user): array
    {
        return $this->memoizeIds($user, 'enrollments', function (User $user) {
            $groupIds = $this->shouldExpandGroupMembership($user)
                ? $this->accessibleGroupIds($user)
                : [];
            $studentIds = $this->directStudentScopedIds($user);

            if ($groupIds === [] && $studentIds === []) {
                return [];
            }

            return Enrollment::query()
                ->when($groupIds !== [] || $studentIds !== [], function (Builder $query) use ($groupIds, $studentIds) {
                    $query->where(function (Builder $builder) use ($groupIds, $studentIds) {
                        if ($groupIds !== []) {
                            $builder->whereIn('group_id', $groupIds);
                        }

                        if ($studentIds !== []) {
                            $method = $groupIds !== [] ? 'orWhereIn' : 'whereIn';
                            $builder->{$method}('student_id', $studentIds);
                        }
                    });
                })
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        });
    }

    public function accessibleGroupIds(?User $user): array
    {
        return $this->memoizeIds($user, 'groups', function (User $user) {
            $ids = collect($this->overrideIds($user, 'group'))
                ->merge($this->naturalTeacherGroupIds($user));

            $studentIds = $this->naturalSelfStudentIds($user);

            if ($studentIds !== []) {
                $ids = $ids->merge(
                    Enrollment::query()
                        ->whereIn('student_id', $studentIds)
                        ->pluck('group_id')
                        ->all(),
                );
            }

            return $this->uniqueIntegers($ids->all());
        });
    }

    public function accessibleParentIds(?User $user): array
    {
        return $this->memoizeIds($user, 'parents', function (User $user) {
            $ids = collect($this->overrideIds($user, 'parent'));

            if ($user->parentProfile?->id) {
                $ids->push($user->parentProfile->id);
            }

            $studentIds = $this->accessibleStudentIds($user);

            if ($studentIds !== []) {
                $ids = $ids->merge(
                    Student::query()
                        ->whereIn('id', $studentIds)
                        ->whereNotNull('parent_id')
                        ->pluck('parent_id')
                        ->all(),
                );
            }

            return $this->uniqueIntegers($ids->all());
        });
    }

    public function accessibleStudentIds(?User $user): array
    {
        return $this->memoizeIds($user, 'students', function (User $user) {
            $ids = collect($this->directStudentScopedIds($user));

            $groupIds = $this->shouldExpandGroupMembership($user)
                ? $this->accessibleGroupIds($user)
                : [];

            if ($groupIds !== []) {
                $ids = $ids->merge(
                    Student::query()
                        ->whereHas('enrollments', fn (Builder $builder) => $builder->whereIn('group_id', $groupIds))
                        ->pluck('id')
                        ->all(),
                );
            }

            return $this->uniqueIntegers($ids->all());
        });
    }

    public function accessibleTeacherIds(?User $user): array
    {
        return $this->memoizeIds($user, 'teachers', function (User $user) {
            $ids = collect($this->overrideIds($user, 'teacher'));

            if ($user->teacherProfile?->id) {
                $ids->push($user->teacherProfile->id);
            }

            return $this->uniqueIntegers($ids->all());
        });
    }

    public function isUnrestricted(?User $user): bool
    {
        if ($user) {
            $user->loadMissing('roles');
        }

        return $user?->hasAnyRole(RoleRegistry::unrestrictedRoles()) ?? false;
    }

    public function scopeActivities(Builder $query, ?User $user): Builder
    {
        if ($this->isUnrestricted($user)) {
            return $query;
        }

        $groupIds = $this->accessibleGroupIds($user);

        if ($groupIds === []) {
            return $query->whereRaw('1 = 0');
        }

        $qualifiedGroupColumn = $query->getModel()->qualifyColumn('group_id');
        $qualifiedAudienceColumn = $query->getModel()->qualifyColumn('audience_scope');

        return $query->where(function (Builder $builder) use ($groupIds, $qualifiedAudienceColumn, $qualifiedGroupColumn) {
            $builder
                ->where($qualifiedAudienceColumn, ActivityAudienceService::SCOPE_ALL_GROUPS)
                ->orWhereIn($qualifiedGroupColumn, $groupIds)
                ->orWhereHas('targetGroups', fn (Builder $groupQuery) => $groupQuery->whereIn('groups.id', $groupIds));
        });
    }

    public function scopeActivityExpenses(Builder $query, ?User $user): Builder
    {
        if ($this->isUnrestricted($user)) {
            return $query;
        }

        return $query->whereHas('activity', fn (Builder $builder) => $this->scopeActivities($builder, $user));
    }

    public function scopeActivityPayments(Builder $query, ?User $user): Builder
    {
        if ($this->isUnrestricted($user)) {
            return $query;
        }

        $enrollmentIds = $this->accessibleEnrollmentIds($user);
        $studentIds = $this->accessibleStudentIds($user);

        if ($enrollmentIds === [] && $studentIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('registration', function (Builder $builder) use ($enrollmentIds, $studentIds) {
            $builder->where(function (Builder $query) use ($enrollmentIds, $studentIds) {
                if ($enrollmentIds !== []) {
                    $query->whereIn('enrollment_id', $enrollmentIds);
                }

                if ($studentIds !== []) {
                    $method = $enrollmentIds !== [] ? 'orWhereIn' : 'whereIn';
                    $query->{$method}('student_id', $studentIds);
                }
            });
        });
    }

    public function scopeActivityRegistrations(Builder $query, ?User $user): Builder
    {
        if ($this->isUnrestricted($user)) {
            return $query;
        }

        $enrollmentIds = $this->accessibleEnrollmentIds($user);
        $studentIds = $this->accessibleStudentIds($user);

        if ($enrollmentIds === [] && $studentIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $builder) use ($enrollmentIds, $studentIds) {
            if ($enrollmentIds !== []) {
                $builder->whereIn('enrollment_id', $enrollmentIds);
            }

            if ($studentIds !== []) {
                $method = $enrollmentIds !== [] ? 'orWhereIn' : 'whereIn';
                $builder->{$method}('student_id', $studentIds);
            }
        });
    }

    public function scopeAssessmentResults(Builder $query, ?User $user): Builder
    {
        if ($this->isUnrestricted($user)) {
            return $query;
        }

        return $this->applyScopedIds($query, 'enrollment_id', $this->accessibleEnrollmentIds($user));
    }

    public function scopeAssessments(Builder $query, ?User $user): Builder
    {
        if ($this->isUnrestricted($user)) {
            return $query;
        }

        $groupIds = $this->accessibleGroupIds($user);

        if ($groupIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $builder) use ($groupIds) {
            $builder
                ->whereIn($builder->getModel()->qualifyColumn('group_id'), $groupIds)
                ->orWhereHas('groups', fn (Builder $groupQuery) => $groupQuery->whereIn('groups.id', $groupIds));
        });
    }

    public function scopeEnrollments(Builder $query, ?User $user): Builder
    {
        if ($this->isUnrestricted($user)) {
            return $query;
        }

        return $this->applyScopedIds($query, 'id', $this->accessibleEnrollmentIds($user));
    }

    public function scopeGroupAttendanceDays(Builder|Relation $query, ?User $user): Builder|Relation
    {
        if ($this->isUnrestricted($user)) {
            return $query;
        }

        return $this->applyScopedIds($query, 'group_id', $this->accessibleGroupIds($user));
    }

    public function scopeGroups(Builder $query, ?User $user): Builder
    {
        if ($this->isUnrestricted($user)) {
            return $query;
        }

        return $this->applyScopedIds($query, 'id', $this->accessibleGroupIds($user));
    }

    public function scopeInvoiceItems(Builder $query, ?User $user): Builder
    {
        if ($this->isUnrestricted($user)) {
            return $query;
        }

        $studentIds = $this->accessibleStudentIds($user);
        $enrollmentIds = $this->accessibleEnrollmentIds($user);

        if ($studentIds === [] && $enrollmentIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $builder) use ($studentIds, $enrollmentIds) {
            if ($studentIds !== []) {
                $builder->whereIn('student_id', $studentIds);
            }

            if ($enrollmentIds !== []) {
                $method = $studentIds !== [] ? 'orWhereIn' : 'whereIn';
                $builder->{$method}('enrollment_id', $enrollmentIds);
            }
        });
    }

    public function scopeInvoices(Builder $query, ?User $user): Builder
    {
        if ($this->isUnrestricted($user)) {
            return $query;
        }

        $parentIds = $this->accessibleParentIds($user);
        $studentIds = $this->accessibleStudentIds($user);

        if ($parentIds === [] && $studentIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $builder) use ($parentIds, $studentIds) {
            if ($parentIds !== []) {
                $builder->whereIn('parent_id', $parentIds);
            }

            if ($studentIds !== []) {
                $method = $parentIds !== [] ? 'orWhereHas' : 'whereHas';
                $builder->{$method}('items', function (Builder $query) use ($studentIds) {
                    $query->where(function (Builder $itemQuery) use ($studentIds) {
                        $itemQuery
                            ->whereIn('student_id', $studentIds)
                            ->orWhereHas('enrollment', fn (Builder $builder) => $builder->whereIn('student_id', $studentIds));
                    });
                });
            }
        });
    }

    public function scopeMemorizationSessions(Builder $query, ?User $user): Builder
    {
        if ($this->isUnrestricted($user)) {
            return $query;
        }

        return $this->applyScopedIds($query, 'enrollment_id', $this->accessibleEnrollmentIds($user));
    }

    public function scopeQuranTests(Builder $query, ?User $user): Builder
    {
        if ($this->isUnrestricted($user)) {
            return $query;
        }

        return $this->applyScopedIds($query, 'enrollment_id', $this->accessibleEnrollmentIds($user));
    }

    public function scopeQuranPartialTests(Builder $query, ?User $user): Builder
    {
        if ($this->isUnrestricted($user)) {
            return $query;
        }

        return $this->applyScopedIds($query, 'enrollment_id', $this->accessibleEnrollmentIds($user));
    }

    public function scopeQuranFinalTests(Builder $query, ?User $user): Builder
    {
        if ($this->isUnrestricted($user)) {
            return $query;
        }

        return $this->applyScopedIds($query, 'enrollment_id', $this->accessibleEnrollmentIds($user));
    }

    public function scopeParents(Builder $query, ?User $user): Builder
    {
        if ($this->isUnrestricted($user)) {
            return $query;
        }

        return $this->applyScopedIds($query, 'id', $this->accessibleParentIds($user));
    }

    public function scopePayments(Builder $query, ?User $user): Builder
    {
        if ($this->isUnrestricted($user)) {
            return $query;
        }

        return $query->whereHas('invoice', fn (Builder $builder) => $this->scopeInvoices($builder, $user));
    }

    public function scopePointTransactions(Builder $query, ?User $user): Builder
    {
        if ($this->isUnrestricted($user)) {
            return $query;
        }

        return $this->applyScopedIds($query, 'enrollment_id', $this->accessibleEnrollmentIds($user));
    }

    public function scopeStudentAttendanceRecords(Builder $query, ?User $user): Builder
    {
        if ($this->isUnrestricted($user)) {
            return $query;
        }

        return $this->applyScopedIds($query, 'enrollment_id', $this->accessibleEnrollmentIds($user));
    }

    public function scopeStudentAttendanceDays(Builder $query, ?User $user): Builder
    {
        if ($this->isUnrestricted($user)) {
            return $query;
        }

        $groupIds = $this->accessibleGroupIds($user);

        if ($groupIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('groupAttendanceDays', fn (Builder $builder) => $builder->whereIn('group_id', $groupIds));
    }

    public function scopeStudentNotes(Builder $query, ?User $user): Builder
    {
        if ($this->isUnrestricted($user)) {
            return $query;
        }

        $studentIds = $this->accessibleStudentIds($user);
        $enrollmentIds = $this->accessibleEnrollmentIds($user);

        if ($studentIds === []) {
            return $query->whereRaw('1 = 0');
        }

        $query
            ->whereIn('student_id', $studentIds)
            ->where(function (Builder $builder) use ($enrollmentIds) {
                $builder->whereNull('enrollment_id');

                if ($enrollmentIds !== []) {
                    $builder->orWhereIn('enrollment_id', $enrollmentIds);
                }
            });

        if ($user?->hasRole(RoleRegistry::TEACHER)) {
            $query->where('visibility', '!=', 'private_management');
        } elseif ($user?->hasAnyRole(RoleRegistry::actorRoles())) {
            $query->where('visibility', 'visible_to_parent');
        }

        return $query;
    }

    public function scopeStudents(Builder $query, ?User $user): Builder
    {
        if ($this->isUnrestricted($user)) {
            return $query;
        }

        return $this->applyScopedIds($query, 'id', $this->accessibleStudentIds($user));
    }

    public function scopeTeacherAttendanceRecords(Builder $query, ?User $user): Builder
    {
        if ($this->isUnrestricted($user)) {
            return $query;
        }

        return $this->applyScopedIds($query, 'teacher_id', $this->accessibleTeacherIds($user));
    }

    public function scopeTeachers(Builder $query, ?User $user): Builder
    {
        if ($this->isUnrestricted($user)) {
            return $query;
        }

        return $this->applyScopedIds($query, 'id', $this->accessibleTeacherIds($user));
    }

    protected function applyScopedIds(Builder|Relation $query, string $column, array $ids): Builder|Relation
    {
        if ($ids === []) {
            return $query->whereRaw('1 = 0');
        }

        $qualifiedColumn = str_contains($column, '.')
            ? $column
            : $query->getModel()->qualifyColumn($column);

        return $query->whereIn($qualifiedColumn, $ids);
    }

    protected function memoizeIds(?User $user, string $key, callable $resolver): array
    {
        if (! $user) {
            return [];
        }

        if ($this->isUnrestricted($user)) {
            return [];
        }

        $cacheKey = $key.'.'.$user->id;

        if (! array_key_exists($cacheKey, $this->memoizedIds)) {
            $user = $this->loadScopeRelations($user);
            $this->memoizedIds[$cacheKey] = $resolver($user);
        }

        return $this->memoizedIds[$cacheKey];
    }

    protected function loadScopeRelations(User $user): User
    {
        if (! array_key_exists($user->id, $this->memoizedUserRelations)) {
            $user->loadMissing([
                'parentProfile',
                'scopeOverrides',
                'studentProfile',
                'teacherProfile',
            ]);

            $this->memoizedUserRelations[$user->id] = true;
        }

        return $user;
    }

    protected function naturalSelfStudentIds(User $user): array
    {
        $ids = [];

        if ($user->parentProfile?->id) {
            $ids = array_merge(
                $ids,
                Student::query()
                    ->where('parent_id', $user->parentProfile->id)
                    ->pluck('id')
                    ->all(),
            );
        }

        if ($user->studentProfile?->id) {
            $ids[] = $user->studentProfile->id;
        }

        return $this->uniqueIntegers($ids);
    }

    protected function naturalTeacherGroupIds(User $user): array
    {
        if (! $user->teacherProfile?->id) {
            return [];
        }

        return Group::query()
            ->where(function (Builder $query) use ($user) {
                $query
                    ->where('teacher_id', $user->teacherProfile->id)
                    ->orWhere('assistant_teacher_id', $user->teacherProfile->id);
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    protected function overrideIds(User $user, string $scopeType): array
    {
        return $user->scopeOverrides
            ->where('scope_type', $scopeType)
            ->pluck('scope_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    protected function directStudentScopedIds(User $user): array
    {
        return $this->uniqueIntegers([
            ...$this->naturalSelfStudentIds($user),
            ...$this->overrideIds($user, 'student'),
        ]);
    }

    protected function shouldExpandGroupMembership(User $user): bool
    {
        $user->loadMissing('roles');

        return ! $user->hasAnyRole([
            RoleRegistry::PARENT,
            RoleRegistry::STUDENT,
        ]);
    }

    protected function uniqueIntegers(array $ids): array
    {
        return collect($ids)
            ->filter(fn ($id) => filled($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
