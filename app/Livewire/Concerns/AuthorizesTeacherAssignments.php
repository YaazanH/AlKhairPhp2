<?php

namespace App\Livewire\Concerns;

use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Invoice;
use App\Models\ParentProfile;
use App\Models\Student;
use App\Models\StudentAttendanceDay;
use App\Models\StudentNote;
use App\Models\Teacher;
use App\Services\AccessScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;

trait AuthorizesTeacherAssignments
{
    protected function accessScopes(): AccessScopeService
    {
        return app(AccessScopeService::class);
    }

    protected function authorizeScopedAssessmentAccess(Assessment $assessment): void
    {
        abort_unless($this->accessScopes()->canAccessAssessment(Auth::user(), $assessment), 403);
    }

    protected function authorizeScopedEnrollmentAccess(Enrollment $enrollment): void
    {
        abort_unless($this->accessScopes()->canAccessEnrollment(Auth::user(), $enrollment), 403);
    }

    protected function authorizeScopedGroupAccess(Group $group): void
    {
        abort_unless($this->accessScopes()->canAccessGroup(Auth::user(), $group), 403);
    }

    protected function authorizeScopedGroupAttendanceDayAccess(\App\Models\GroupAttendanceDay $groupAttendanceDay): void
    {
        abort_unless($this->accessScopes()->canAccessGroupAttendanceDay(Auth::user(), $groupAttendanceDay), 403);
    }

    protected function authorizeScopedInvoiceAccess(Invoice $invoice): void
    {
        abort_unless($this->accessScopes()->canAccessInvoice(Auth::user(), $invoice), 403);
    }

    protected function authorizeScopedParentAccess(ParentProfile $parentProfile): void
    {
        abort_unless($this->accessScopes()->canAccessParent(Auth::user(), $parentProfile), 403);
    }

    protected function authorizeScopedStudentAccess(Student $student): void
    {
        abort_unless($this->accessScopes()->canAccessStudent(Auth::user(), $student), 403);
    }

    protected function authorizeScopedStudentAttendanceDayAccess(StudentAttendanceDay $studentAttendanceDay): void
    {
        abort_unless($this->accessScopes()->canAccessStudentAttendanceDay(Auth::user(), $studentAttendanceDay), 403);
    }

    protected function authorizeScopedTeacherAccess(Teacher $teacher): void
    {
        abort_unless($this->accessScopes()->canAccessTeacher(Auth::user(), $teacher), 403);
    }

    protected function linkedTeacherForPermission(string $permission): ?Teacher
    {
        $user = Auth::user();

        if (! $user?->can($permission)) {
            return null;
        }

        $user->loadMissing('teacherProfile');

        return $user->teacherProfile;
    }

    protected function authorizeTeacherGroupAccess(Group $group): void
    {
        $this->authorizeScopedGroupAccess($group);
    }

    protected function authorizeTeacherEnrollmentAccess(Enrollment $enrollment): void
    {
        $this->authorizeScopedEnrollmentAccess($enrollment);
    }

    protected function authorizeTeacherAssessmentAccess(Assessment $assessment): void
    {
        $this->authorizeScopedAssessmentAccess($assessment);
    }

    protected function scopeActivitiesQuery(Builder $query): Builder
    {
        return $this->accessScopes()->scopeActivities($query, Auth::user());
    }

    protected function scopeAssessmentResultsQuery(Builder $query): Builder
    {
        return $this->accessScopes()->scopeAssessmentResults($query, Auth::user());
    }

    protected function scopeAssessmentsQuery(Builder $query): Builder
    {
        return $this->accessScopes()->scopeAssessments($query, Auth::user());
    }

    protected function scopeEnrollmentsQuery(Builder $query): Builder
    {
        return $this->accessScopes()->scopeEnrollments($query, Auth::user());
    }

    protected function scopeGroupAttendanceDaysQuery(Builder|Relation $query): Builder|Relation
    {
        return $this->accessScopes()->scopeGroupAttendanceDays($query, Auth::user());
    }

    protected function scopeGroupsQuery(Builder $query): Builder
    {
        return $this->accessScopes()->scopeGroups($query, Auth::user());
    }

    protected function scopeInvoiceItemsQuery(Builder $query): Builder
    {
        return $this->accessScopes()->scopeInvoiceItems($query, Auth::user());
    }

    protected function scopeInvoicesQuery(Builder $query): Builder
    {
        return $this->accessScopes()->scopeInvoices($query, Auth::user());
    }

    protected function scopeMemorizationSessionsQuery(Builder $query): Builder
    {
        return $this->accessScopes()->scopeMemorizationSessions($query, Auth::user());
    }

    protected function scopeParentsQuery(Builder $query): Builder
    {
        return $this->accessScopes()->scopeParents($query, Auth::user());
    }

    protected function scopePaymentsQuery(Builder $query): Builder
    {
        return $this->accessScopes()->scopePayments($query, Auth::user());
    }

    protected function scopePointTransactionsQuery(Builder $query): Builder
    {
        return $this->accessScopes()->scopePointTransactions($query, Auth::user());
    }

    protected function scopeQuranTestsQuery(Builder $query): Builder
    {
        return $this->accessScopes()->scopeQuranTests($query, Auth::user());
    }

    protected function scopeQuranPartialTestsQuery(Builder $query): Builder
    {
        return $this->accessScopes()->scopeQuranPartialTests($query, Auth::user());
    }

    protected function scopeQuranFinalTestsQuery(Builder $query): Builder
    {
        return $this->accessScopes()->scopeQuranFinalTests($query, Auth::user());
    }

    protected function scopeStudentAttendanceRecordsQuery(Builder $query): Builder
    {
        return $this->accessScopes()->scopeStudentAttendanceRecords($query, Auth::user());
    }

    protected function scopeStudentAttendanceDaysQuery(Builder $query): Builder
    {
        return $this->accessScopes()->scopeStudentAttendanceDays($query, Auth::user());
    }

    protected function scopeStudentNotesQuery(Builder $query): Builder
    {
        return $this->accessScopes()->scopeStudentNotes($query, Auth::user());
    }

    protected function scopeStudentsQuery(Builder $query): Builder
    {
        return $this->accessScopes()->scopeStudents($query, Auth::user());
    }

    protected function scopeTeacherAttendanceRecordsQuery(Builder $query): Builder
    {
        return $this->accessScopes()->scopeTeacherAttendanceRecords($query, Auth::user());
    }

    protected function scopeTeachersQuery(Builder $query): Builder
    {
        return $this->accessScopes()->scopeTeachers($query, Auth::user());
    }
}
