<?php

namespace App\Services;

use App\Models\User;
use App\Services\Landlord\CurrentModuleAccess;
use App\Support\OperationalFeatureSettings;

class MobileNavigationService
{
    public function for(User $user): array
    {
        $items = [
            $this->item('dashboard', 'ui.nav.dashboard', 'home', '/dashboard'),
        ];

        if ($user->parentProfile && app(CurrentModuleAccess::class)->enabled('parent_portal')) {
            $items[] = $this->item('parent', 'ui.nav.family_activities', 'heart', '/parent/summary');
        }

        $definitions = [
            ['students', 'ui.nav.students', 'student-graduates', '/students', ['students.view']],
            ['groups', 'ui.nav.groups', 'people-circle', '/groups', ['groups.view']],
            ['enrollments', 'ui.nav.enrollments', 'enrollment-add', '/enrollments', ['enrollments.view']],
            ['attendance', 'attendance.title', 'clipboard-student', '/student-attendance/days', ['attendance.student.view', 'attendance.teacher.view']],
            ['memorization', 'ui.nav.memorization', 'quran-stand', '/memorization', ['memorization.view']],
            ['quran_tests', 'ui.nav.quran_tests', 'certificate-landscape', '/quran-tests', ['quran-tests.view', 'quran-awqaf-tests.view']],
            ['points', 'ui.nav.point_ledger', 'achievement-star', '/points', ['points.view']],
            ['assessments', 'ui.nav.assessments', 'assessment-review', '/assessments', ['assessments.view']],
            ['student_notes', 'ui.nav.student_notes', 'note-document', '/student-notes', ['student-notes.view']],
            ['curricula', 'ui.nav.curricula', 'books-leaning', '/curricula', ['curricula.manage', 'curricula.record']],
            ['activities', 'ui.nav.activities', 'sparkles', '/activities', ['activities.view']],
            ['invoices', 'ui.nav.invoices', 'document-text', '/invoices', ['invoices.view']],
            ['reports', 'ui.nav.reports', 'chart-bar', '/reports/overview', ['reports.view']],
        ];

        foreach ($definitions as [$key, $label, $icon, $endpoint, $permissions]) {
            if ($key === 'activities' && ! OperationalFeatureSettings::activitiesEnabled()) {
                continue;
            }

            if (! collect($permissions)->contains(fn (string $permission): bool => app(CurrentModuleAccess::class)->permissionAvailable($permission) && $user->can($permission))) {
                continue;
            }

            if ($key === 'attendance' && ! app(CurrentModuleAccess::class)->enabled('student_attendance')) {
                $endpoint = '/teacher-attendance/days';
            }

            $items[] = $this->item($key, $label, $icon, $endpoint);
        }

        return $items;
    }

    private function item(string $key, string $labelKey, string $icon, ?string $endpoint): array
    {
        return [
            'key' => $key,
            'label' => __($labelKey),
            'icon' => $icon,
            'endpoint' => $endpoint,
            'available' => $endpoint !== null,
        ];
    }
}
