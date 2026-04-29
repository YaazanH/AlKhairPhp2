<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\User;

class SidebarNavigationService
{
    protected const CUSTOM_GROUP_PREFIX = 'custom_';

    public function defaultGroups(): array
    {
        return [
            'platform' => ['title_key' => 'ui.nav.platform', 'sort_order' => 10],
            'people' => ['title_key' => 'ui.nav.people', 'sort_order' => 20],
            'academics' => ['title_key' => 'ui.nav.academics', 'sort_order' => 30],
            'tracking_attendance' => ['title_key' => 'ui.nav.tracking_attendance', 'sort_order' => 40],
            'tracking_quran' => ['title_key' => 'ui.nav.tracking_quran', 'sort_order' => 50],
            'tracking_performance' => ['title_key' => 'ui.nav.tracking_performance', 'sort_order' => 60],
            'tracking_tools' => ['title_key' => 'ui.nav.tracking_tools', 'sort_order' => 70],
            'finance' => ['title_key' => 'ui.nav.finance', 'sort_order' => 80],
            'configuration' => ['title_key' => 'ui.nav.configuration', 'sort_order' => 90],
            'identity_tools' => ['title_key' => 'ui.nav.identity_tools', 'sort_order' => 100],
        ];
    }

    public function defaultItems(): array
    {
        return [
            'dashboard' => $this->item('ui.nav.dashboard', 'home', 'dashboard', ['dashboard'], 'platform', 10),
            'reports' => $this->item('ui.nav.reports', 'chart-bar', 'reports.index', ['reports.*'], 'platform', 20, ['reports.view']),

            'users' => $this->item('ui.nav.users', 'users', 'users.index', ['users.*'], 'people', 10, ['users.view']),
            'parents' => $this->item('ui.nav.parents', 'user-group', 'parents.index', ['parents.*'], 'people', 20, ['parents.view']),
            'teachers' => $this->item('ui.nav.teachers', 'academic-cap', 'teachers.index', ['teachers.*'], 'people', 30, ['teachers.view']),
            'students' => $this->item('ui.nav.students', 'identification', 'students.index', ['students.index', 'students.progress', 'students.files'], 'people', 40, ['students.view']),
            'bulk_student_photos' => $this->item('ui.nav.bulk_student_photos', 'photo', 'students.bulk-photos', ['students.bulk-photos'], 'people', 50, ['students.update']),

            'courses' => $this->item('ui.nav.courses', 'book-open', 'courses.index', ['courses.*'], 'academics', 10, ['courses.view']),
            'groups' => $this->item('ui.nav.groups', 'rectangle-group', 'groups.index', ['groups.*'], 'academics', 20, ['groups.view']),
            'enrollments' => $this->item('ui.nav.enrollments', 'clipboard-document-list', 'enrollments.index', ['enrollments.*'], 'academics', 30, ['enrollments.view']),

            'student_attendance' => $this->item('ui.nav.student_attendance', 'calendar-days', 'student-attendance.index', ['student-attendance.*', 'groups.attendance'], 'tracking_attendance', 10, ['attendance.student.view']),
            'teacher_attendance' => $this->item('ui.nav.teacher_attendance', 'clipboard-document-check', 'teachers.attendance', ['teachers.attendance'], 'tracking_attendance', 20, ['attendance.teacher.view']),

            'memorization' => $this->item('ui.nav.memorization', 'book-open-text', 'memorization.index', ['memorization.index', 'enrollments.memorization'], 'tracking_quran', 10, ['memorization.view']),
            'enter_memorize' => $this->item('ui.nav.enter_memorize', 'pencil-square', 'memorization.quick-entry', ['memorization.quick-entry'], 'tracking_quran', 20, ['memorization.record']),
            'quran_partial_tests' => $this->item('ui.nav.quran_partial_tests', 'squares-2x2', 'quran-partial-tests.index', ['quran-partial-tests.*'], 'tracking_quran', 30, ['quran-partial-tests.view']),
            'quran_final_tests' => $this->item('ui.nav.quran_final_tests', 'check-badge', 'quran-final-tests.index', ['quran-final-tests.*'], 'tracking_quran', 40, ['quran-final-tests.view']),
            'quran_tests' => $this->item('ui.nav.quran_tests', 'document-check', 'quran-tests.index', ['quran-tests.*', 'enrollments.quran-tests'], 'tracking_quran', 50, ['quran-tests.view']),

            'assessments' => $this->item('ui.nav.assessments', 'chart-pie', 'assessments.index', ['assessments.*'], 'tracking_performance', 10, ['assessments.view']),
            'point_ledger' => $this->item('ui.nav.point_ledger', 'trophy', 'points.index', ['points.*', 'enrollments.points'], 'tracking_performance', 20, ['points.view']),

            'student_notes' => $this->item('ui.nav.student_notes', 'pencil-square', 'student-notes.index', ['student-notes.*'], 'tracking_tools', 10, ['student-notes.view']),
            'scanner_import' => $this->item('ui.nav.scanner_import', 'qr-code', 'barcode-actions.import', ['barcode-actions.import'], 'tracking_tools', 20, ['barcode-scans.import']),

            'activities' => $this->item('ui.nav.activities', 'sparkles', 'activities.index', ['activities.index', 'activities.finance'], 'finance', 10, ['activities.view']),
            'family_activities' => $this->item('ui.nav.family_activities', 'heart', 'activities.family', ['activities.family'], 'finance', 20, ['activities.responses.view']),
            'invoices' => $this->item('ui.nav.invoices', 'document-currency-dollar', 'invoices.index', ['invoices.*'], 'finance', 30, ['invoices.view']),

            'dashboard_settings' => $this->item('ui.nav.dashboard_settings', 'cog-6-tooth', 'settings.organization', ['settings.organization', 'settings.tracking', 'settings.course-completion', 'settings.points', 'settings.finance', 'settings.access-control', 'settings.sidebar-navigation'], 'configuration', 10, ['settings.manage']),
            'public_website_settings' => $this->item('ui.nav.public_website_settings', 'globe-alt', 'settings.website', ['settings.website', 'settings.website.pages', 'settings.website.navigation'], 'configuration', 20, ['website.manage']),

            'print_templates' => $this->item('ui.nav.print_templates', 'document-duplicate', 'print-templates.templates.index', ['print-templates.*'], 'identity_tools', 10, ['id-cards.view']),
            'id_card_templates' => $this->item('ui.nav.id_card_templates', 'identification', 'id-cards.templates.index', ['id-cards.templates.*'], 'identity_tools', 20, ['id-cards.view']),
            'id_card_print' => $this->item('ui.nav.id_card_print', 'printer', 'id-cards.print.create', ['id-cards.print.*'], 'identity_tools', 30, ['id-cards.print']),
            'action_barcodes' => $this->item('ui.nav.action_barcodes', 'qr-code', 'barcode-actions.index', ['barcode-actions.index', 'barcode-actions.print.*'], 'identity_tools', 40, ['barcode-actions.view']),
        ];
    }

    public function settings(): array
    {
        $stored = AppSetting::groupValues('sidebar_navigation');
        $groupSettings = is_array($stored->get('groups')) ? $stored->get('groups') : [];
        $itemSettings = is_array($stored->get('items')) ? $stored->get('items') : [];
        $defaultGroups = $this->defaultGroups();

        $groups = [];

        foreach ($defaultGroups as $key => $definition) {
            $groups[$key] = [
                'title' => (string) ($groupSettings[$key]['title'] ?? ''),
                'sort_order' => (int) ($groupSettings[$key]['sort_order'] ?? $definition['sort_order']),
                'is_custom' => false,
            ];
        }

        foreach ($groupSettings as $key => $group) {
            if (isset($groups[$key])) {
                continue;
            }

            if (! $this->isValidCustomGroupKey($key)) {
                continue;
            }

            $title = trim((string) ($group['title'] ?? ''));

            if ($title === '') {
                continue;
            }

            $groups[$key] = [
                'title' => $title,
                'sort_order' => max(0, (int) ($group['sort_order'] ?? 999)),
                'is_custom' => true,
            ];
        }

        $knownGroupKeys = array_keys($groups);
        $items = [];

        foreach ($this->defaultItems() as $key => $definition) {
            $configuredGroupKey = (string) ($itemSettings[$key]['group_key'] ?? $definition['group_key']);

            $items[$key] = [
                'group_key' => in_array($configuredGroupKey, $knownGroupKeys, true)
                    ? $configuredGroupKey
                    : $definition['group_key'],
                'sort_order' => (int) ($itemSettings[$key]['sort_order'] ?? $definition['sort_order']),
            ];
        }

        return [
            'groups' => $groups,
            'items' => $items,
        ];
    }

    public function editableGroups(): array
    {
        $settings = $this->settings();
        $groups = [];
        $defaultGroups = $this->defaultGroups();

        foreach ($settings['groups'] as $key => $groupSetting) {
            $definition = $defaultGroups[$key] ?? null;

            $groups[$key] = [
                'key' => $key,
                'default_title' => $definition ? __($definition['title_key']) : '',
                'title' => $groupSetting['title'],
                'sort_order' => $groupSetting['sort_order'],
                'is_custom' => (bool) ($groupSetting['is_custom'] ?? ! $definition),
            ];
        }

        uasort($groups, function (array $left, array $right): int {
            return [$left['sort_order'], $left['title'] ?: $left['default_title']] <=> [$right['sort_order'], $right['title'] ?: $right['default_title']];
        });

        return $groups;
    }

    public function editableItems(): array
    {
        $settings = $this->settings();
        $items = [];

        foreach ($this->defaultItems() as $key => $definition) {
            $items[$key] = [
                'key' => $key,
                'label' => __($definition['label_key']),
                'group_key' => $settings['items'][$key]['group_key'],
                'sort_order' => $settings['items'][$key]['sort_order'],
            ];
        }

        uasort($items, fn (array $left, array $right) => [$left['group_key'], $left['sort_order'], $left['label']] <=> [$right['group_key'], $right['sort_order'], $right['label']]);

        return $items;
    }

    public function save(array $groups, array $items): void
    {
        $defaultGroups = $this->defaultGroups();
        $defaultItems = $this->defaultItems();
        $knownItems = array_keys($defaultItems);

        $cleanGroups = [];

        foreach ($defaultGroups as $key => $definition) {
            $group = $groups[$key] ?? [];
            $cleanGroups[$key] = [
                'title' => trim((string) ($group['title'] ?? '')),
                'sort_order' => max(0, (int) ($group['sort_order'] ?? $definition['sort_order'])),
                'is_custom' => false,
            ];
        }

        foreach ($groups as $key => $group) {
            if (isset($defaultGroups[$key]) || ! $this->isValidCustomGroupKey($key)) {
                continue;
            }

            $title = trim((string) ($group['title'] ?? ''));

            if ($title === '') {
                continue;
            }

            $cleanGroups[$key] = [
                'title' => $title,
                'sort_order' => max(0, (int) ($group['sort_order'] ?? 999)),
                'is_custom' => true,
            ];
        }

        $knownGroups = array_keys($cleanGroups);
        $cleanItems = [];

        foreach ($items as $key => $item) {
            if (! in_array($key, $knownItems, true)) {
                continue;
            }

            $groupKey = (string) ($item['group_key'] ?? $defaultItems[$key]['group_key']);

            $cleanItems[$key] = [
                'group_key' => in_array($groupKey, $knownGroups, true) ? $groupKey : $defaultItems[$key]['group_key'],
                'sort_order' => max(0, (int) ($item['sort_order'] ?? $defaultItems[$key]['sort_order'])),
            ];
        }

        AppSetting::storeValue('sidebar_navigation', 'groups', $cleanGroups, 'json');
        AppSetting::storeValue('sidebar_navigation', 'items', $cleanItems, 'json');
    }

    public function sidebarFor(?User $user): array
    {
        if (! $user) {
            return [];
        }

        $settings = $this->settings();
        $groups = [];
        $defaultGroups = $this->defaultGroups();

        foreach ($settings['groups'] as $groupKey => $groupDefinition) {
            $items = [];

            foreach ($this->defaultItems() as $itemKey => $itemDefinition) {
                $configuredGroupKey = $settings['items'][$itemKey]['group_key'] ?? $itemDefinition['group_key'];

                if ($configuredGroupKey !== $groupKey || ! $this->userCanSeeItem($user, $itemDefinition)) {
                    continue;
                }

                $items[] = [
                    'key' => $itemKey,
                    'label' => __($itemDefinition['label_key']),
                    'icon' => $itemDefinition['icon'],
                    'href' => route($itemDefinition['route_name']),
                    'current' => request()->routeIs(...$itemDefinition['current_patterns']),
                    'sort_order' => $settings['items'][$itemKey]['sort_order'] ?? $itemDefinition['sort_order'],
                ];
            }

            if ($items === []) {
                continue;
            }

            usort($items, fn (array $left, array $right) => [$left['sort_order'], $left['label']] <=> [$right['sort_order'], $right['label']]);

            $customTitle = trim((string) ($groupDefinition['title'] ?? ''));

            $groups[] = [
                'key' => $groupKey,
                'title' => isset($defaultGroups[$groupKey])
                    ? ($customTitle !== '' ? $customTitle : __($defaultGroups[$groupKey]['title_key']))
                    : $customTitle,
                'sort_order' => $groupDefinition['sort_order'] ?? ($defaultGroups[$groupKey]['sort_order'] ?? 999),
                'items' => $items,
            ];
        }

        usort($groups, fn (array $left, array $right) => [$left['sort_order'], $left['title']] <=> [$right['sort_order'], $right['title']]);

        return $groups;
    }

    protected function item(
        string $labelKey,
        string $icon,
        string $routeName,
        array $currentPatterns,
        string $groupKey,
        int $sortOrder,
        array $requiredPermissions = [],
    ): array {
        return [
            'label_key' => $labelKey,
            'icon' => $icon,
            'route_name' => $routeName,
            'current_patterns' => $currentPatterns,
            'group_key' => $groupKey,
            'sort_order' => $sortOrder,
            'required_permissions' => $requiredPermissions,
        ];
    }

    protected function userCanSeeItem(User $user, array $itemDefinition): bool
    {
        $permissions = $itemDefinition['required_permissions'] ?? [];

        if ($permissions === []) {
            return true;
        }

        foreach ($permissions as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    protected function isValidCustomGroupKey(string $key): bool
    {
        return str_starts_with($key, self::CUSTOM_GROUP_PREFIX)
            && (bool) preg_match('/^'.preg_quote(self::CUSTOM_GROUP_PREFIX, '/').'[a-z0-9_]+$/', $key);
    }
}
