<?php

namespace App\Services\Landlord;

use App\Models\User;
use Illuminate\Http\Request;

class CurrentModuleAccess
{
    private ?Request $snapshotRequest = null;

    private ?string $snapshotTenant = null;

    private array $modules = [];

    public function enabled(string $module): bool
    {
        $context = app(TenantContext::class);
        if (! $context->hasTenant()) {
            return true;
        }
        if ($this->snapshotRequest !== request() || $this->snapshotTenant !== $context->tenant()->uuid) {
            $this->modules = app(TenantModuleAccess::class)->snapshot($context->tenant())['enabled'];
            $this->snapshotRequest = request();
            $this->snapshotTenant = $context->tenant()->uuid;
        }

        return in_array($module, $this->modules, true);
    }

    public function ensure(string $module): void
    {
        abort_unless($this->enabled($module), 403, 'module_disabled: '.$module);
    }

    public function permissionAvailable(string $permission): bool
    {
        if ($permission === 'dashboard.parent.view' || str_starts_with($permission, 'activities.responses.')) {
            return $this->enabled('parent_portal') && ($permission === 'dashboard.parent.view' || $this->enabled('activities'));
        }
        $prefix = explode('.', $permission)[0];
        $module = match ($prefix) {
            'parents' => 'parents',
            'students', 'student-notes' => 'students',
            'teachers' => 'teachers',
            'courses', 'groups', 'enrollments' => 'classes',
            'memorization' => 'memorization',
            'quran-tests', 'quran-partial-tests', 'quran-final-tests', 'quran-awqaf-tests' => 'quran_tests',
            'assessments', 'assessment-results' => 'assessments',
            'points' => 'points_rewards',
            'activities' => 'activities',
            'finance' => 'finance',
            'invoices', 'payments' => 'student_billing',
            'curricula' => 'curriculum',
            'website' => 'public_website',
            default => null,
        };
        if (str_starts_with($permission, 'attendance.student.')) {
            $module = 'student_attendance';
        } elseif (str_starts_with($permission, 'attendance.teacher.')) {
            $module = 'teacher_attendance';
        }

        return $module === null || $this->enabled($module);
    }

    public function portalAccountAllowed(User $user): bool
    {
        $roles = $user->getRoleNames();
        $parentOnly = $roles->contains('parent') && $roles->reject(fn ($role) => $role === 'parent')->isEmpty();

        return ! $parentOnly || $this->enabled('parent_portal');
    }

    // Explicit field ownership for people/parent API payloads, including nested summaries.
    public function filterPayload(array $payload, bool $activityPayload = false): array
    {
        $fields = [
            'parents' => ['parent', 'parent_id', 'parent_name', 'father_name', 'mother_name', 'father_phone', 'mother_phone', 'home_phone'],
            'classes' => ['group', 'groups', 'target_groups', 'enrollment', 'enrollments', 'active_enrollments', 'enrollments_count'],
            'memorization' => ['memorized_pages', 'memorized_pages_cached', 'quran_current_juz', 'quran_current_juz_id'],
            'points_rewards' => ['points', 'final_points_cached'],
            'student_billing' => ['invoice_total', 'paid_total'],
            'activities' => ['available_activity_responses'],
            'finance' => ['fee_amount', 'paid', 'paid_amount', 'remaining_amount', 'collected_revenue', 'expected_revenue', 'expense_total'],
        ];
        $fields[$activityPayload ? 'finance' : 'student_billing'][] = 'balance';
        $hidden = [];
        foreach ($fields as $module => $keys) {
            if (! $this->enabled($module)) {
                $hidden = array_merge($hidden, $keys);
            }
        }
        $filter = function (array $data) use (&$filter, $hidden): array {
            foreach ($data as $key => $value) {
                if (in_array($key, $hidden, true)) {
                    unset($data[$key]);
                } elseif (is_array($value)) {
                    $data[$key] = $filter($value);
                }
            }

            return $data;
        };

        return $filter($payload);
    }
}
