<?php

namespace App\Services\StandaloneTransfer;

use RuntimeException;

class TransferContract
{
    public const EXCLUDED = ['migrations', 'sessions', 'password_reset_tokens', 'personal_access_tokens', 'cache', 'cache_locks', 'jobs', 'failed_jobs', 'job_batches', 'system_backups'];

    public function schema(): array
    {
        return json_decode(file_get_contents(resource_path('standalone-transfer/v1-schema.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public function tables(): array
    {
        return array_diff_key($this->schema()['tables'], array_flip(self::EXCLUDED));
    }

    public function path(string $path): string
    {
        if ($path === '' || strlen($path) > 1024 || preg_match('/[\\\\:\x00-\x1f\x7f]/', $path)
            || str_starts_with($path, '/') || array_intersect(explode('/', $path), ['.', '..', ''])) {
            throw new RuntimeException('unsafe_path');
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment !== rtrim($segment, '. ')
                || preg_match('/^(?:CON|PRN|AUX|NUL|COM[0-9]+|LPT[0-9]+)(?:\.|$)/i', $segment)) {
                throw new RuntimeException('unsafe_path');
            }
        }

        return $path;
    }

    public function encode(array $row): string
    {
        return json_encode(array_map(fn ($value) => $value === null ? null : base64_encode((string) $value), $row), JSON_THROW_ON_ERROR)."\n";
    }

    public function decode(string $line, array $columns): array
    {
        $row = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($row) || array_diff(array_keys($row), $columns) || array_diff($columns, array_keys($row))) {
            throw new RuntimeException('invalid_row');
        }
        foreach ($row as &$value) {
            if ($value !== null && (! is_string($value) || ($value = base64_decode($value, true)) === false)) {
                throw new RuntimeException('invalid_row');
            }
        }

        return $row;
    }

    public function requiredModules(array $counts): array
    {
        // Reference catalogs and broad role permissions alone are not usage evidence.
        $mapping = [
            'students' => ['students', 'student_files', 'student_notes', 'student_external_memorized_juz'],
            'parents' => ['parents'], 'teachers' => ['teachers'],
            'classes' => ['courses', 'groups', 'enrollments', 'group_schedules', 'course_schedules', 'course_calendar_entries'],
            'student_attendance' => ['student_attendance_days', 'student_attendance_records', 'group_attendance_days'],
            'teacher_attendance' => ['teacher_attendance_days', 'teacher_attendance_records', 'teacher_attendance_inclusions', 'teacher_attendance_exclusions'],
            'memorization' => ['memorization_sessions', 'memorization_session_pages', 'student_page_achievements'],
            'quran_tests' => ['quran_tests', 'quran_partial_tests', 'quran_partial_test_parts', 'quran_partial_test_attempts', 'quran_final_tests', 'quran_final_test_attempts', 'awqaf_subject_tests'],
            'assessments' => ['assessments', 'assessment_groups', 'assessment_results'],
            'points_rewards' => ['point_transactions', 'course_point_market_invoices', 'course_point_market_items', 'course_point_market_departments'],
            'activities' => ['activities', 'activity_group_targets', 'activity_registrations'],
            'finance' => ['finance_transactions', 'finance_requests', 'finance_cash_box_transfers', 'finance_currency_exchanges', 'finance_generated_reports', 'finance_request_attachments', 'activity_payments', 'activity_expenses'],
            'student_billing' => ['invoices', 'invoice_items', 'payments'],
            'curriculum' => ['curricula', 'curriculum_lessons', 'curriculum_resources', 'curriculum_subjects', 'group_curriculum_lesson_progresses', 'group_curriculum_topic_progresses', 'group_custom_curriculum_lessons'],
            'custom_templates' => ['print_templates'], 'id_cards' => ['id_card_templates', 'student_card_prints'],
            'public_website' => ['website_pages', 'website_menus', 'website_menu_items'],
        ];
        $required = [];
        foreach ($mapping as $module => $tables) {
            $evidence = array_values(array_filter($tables, fn ($table) => ($counts[$table] ?? 0) > 0));
            if ($evidence) {
                $required[$module] = $evidence;
            }
        }

        return $required;
    }
}
