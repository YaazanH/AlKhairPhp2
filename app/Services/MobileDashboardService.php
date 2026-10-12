<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Invoice;
use App\Models\ParentProfile;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;

class MobileDashboardService
{
    public function __construct(protected AccessScopeService $scope) {}

    public function for(User $user): array
    {
        $metrics = [];

        if ($user->can('students.view')) {
            $metrics['active_students'] = $this->scope->scopeStudents(
                Student::query()->where('status', 'active'),
                $user,
            )->count();
        }

        if ($user->can('groups.view')) {
            $metrics['active_groups'] = $this->scope->scopeGroups(
                Group::query()->where('is_active', true),
                $user,
            )->count();
        }

        if ($user->can('enrollments.view')) {
            $enrollments = $this->scope->scopeEnrollments(
                Enrollment::query()->where('status', 'active'),
                $user,
            );

            $metrics['active_enrollments'] = (clone $enrollments)->count();
            $metrics['memorized_pages'] = (int) (clone $enrollments)->sum('memorized_pages_cached');
            $metrics['points'] = (int) (clone $enrollments)->sum('final_points_cached');
        }

        if ($user->can('teachers.view')) {
            $metrics['teachers'] = $this->scope->scopeTeachers(Teacher::query(), $user)->count();
        }

        if ($user->can('parents.view')) {
            $metrics['parents'] = $this->scope->scopeParents(ParentProfile::query(), $user)->count();
        }

        if ($user->can('assessments.view')) {
            $metrics['active_assessments'] = $this->scope->scopeAssessments(
                Assessment::query()->where('is_active', true),
                $user,
            )->count();
        }

        if ($user->can('activities.view')) {
            $metrics['active_activities'] = $this->scope->scopeActivities(
                Activity::query()->where('is_active', true),
                $user,
            )->count();
        }

        if ($user->can('invoices.view')) {
            $invoices = $this->scope->scopeInvoices(
                Invoice::query()->with(['payments' => fn ($query) => $query->whereNull('voided_at')]),
                $user,
            )->get();

            $metrics['invoice_balance'] = round($invoices->sum(
                fn (Invoice $invoice): float => max(0, (float) $invoice->total - (float) $invoice->payments->sum('amount'))
            ), 2);
        }

        return [
            'role' => $user->primaryRoleName(),
            'generated_at' => now()->toIso8601String(),
            'metrics' => $metrics,
        ];
    }
}
