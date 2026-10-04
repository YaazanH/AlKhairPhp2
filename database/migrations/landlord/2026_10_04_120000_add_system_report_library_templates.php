<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    protected $connection = 'landlord';

    public function up(): void
    {
        Schema::table('platform_report_library_items', function (Blueprint $table): void {
            $table->string('system_key', 100)->nullable()->after('uuid')->unique();
            $table->boolean('is_system')->default(false)->after('system_key')->index();
        });

        foreach ($this->templates() as $template) {
            $this->publishSystemTemplate($template);
        }
    }

    public function down(): void
    {
        DB::connection('landlord')->table('platform_report_library_items')->where('is_system', true)->delete();

        Schema::table('platform_report_library_items', function (Blueprint $table): void {
            $table->dropUnique(['system_key']);
            $table->dropIndex(['is_system']);
            $table->dropColumn(['system_key', 'is_system']);
        });
    }

    private function publishSystemTemplate(array $template): void
    {
        $connection = DB::connection('landlord');
        $now = now();
        $itemId = $connection->table('platform_report_library_items')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'system_key' => $template['key'],
            'is_system' => true,
            'kind' => $template['kind'],
            'name' => json_encode($template['name'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'description' => json_encode($template['description'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'draft_definition' => json_encode($template['definition'], JSON_THROW_ON_ERROR),
            'required_modules' => json_encode($template['modules'], JSON_THROW_ON_ERROR),
            'latest_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $revisionId = $connection->table('platform_report_library_revisions')->insertGetId([
            'library_item_id' => $itemId,
            'version' => 1,
            'kind' => $template['kind'],
            'name' => json_encode($template['name'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'description' => json_encode($template['description'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'definition' => json_encode($template['definition'], JSON_THROW_ON_ERROR),
            'required_modules' => json_encode($template['modules'], JSON_THROW_ON_ERROR),
            'published_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $connection->table('platform_report_library_items')->where('id', $itemId)->update([
            'published_revision_id' => $revisionId,
        ]);
    }

    private function templates(): array
    {
        return [
            $this->template(
                'students-by-group',
                ['en' => 'Students by group', 'ar' => 'الطلاب حسب المجموعة'],
                ['en' => 'See the distribution of students across their current groups.', 'ar' => 'اعرض توزيع الطلاب على مجموعاتهم الحالية.'],
                'students',
                ['student_number', 'full_name', 'status', 'grade_level', 'current_group'],
                'current_group',
                'donut',
                ['students'],
            ),
            $this->template(
                'attendance-risk',
                ['en' => 'Student attendance risk', 'ar' => 'مخاطر حضور الطلاب'],
                ['en' => 'Review attendance records grouped by presence result to identify follow-up needs.', 'ar' => 'راجع سجلات الحضور مجمعة حسب نتيجة الحضور لتحديد الحالات التي تحتاج إلى متابعة.'],
                'student_attendance',
                ['attendance_date', 'student_number', 'full_name', 'attendance_status', 'presence_result'],
                'presence_result',
                'bar',
                ['student_attendance'],
            ),
            $this->template(
                'quran-test-outcomes',
                ['en' => 'Quran test outcomes', 'ar' => 'نتائج اختبارات القرآن'],
                ['en' => 'Compare passed, failed, and cancelled Quran test attempts.', 'ar' => 'قارن محاولات اختبارات القرآن الناجحة والراسبة والملغاة.'],
                'quran_tests',
                ['tested_on', 'student_number', 'full_name', 'test_type', 'juz_number', 'test_status', 'score'],
                'test_status',
                'bar',
                ['quran_tests'],
            ),
            $this->template(
                'assessment-performance',
                ['en' => 'Assessment performance', 'ar' => 'أداء التقييمات'],
                ['en' => 'Summarise student assessment results and average scores by result status.', 'ar' => 'لخص نتائج تقييم الطلاب ومتوسط الدرجات حسب حالة النتيجة.'],
                'assessment_results',
                ['due_at', 'assessment_title', 'full_name', 'score', 'result_status', 'attempt_number'],
                'result_status',
                'donut',
                ['assessments'],
                [['operation' => 'count', 'field' => null], ['operation' => 'avg', 'field' => 'score']],
            ),
            $this->template(
                'teacher-workload',
                ['en' => 'Teacher workload', 'ar' => 'عبء عمل المعلمين'],
                ['en' => 'Compare active teachers by status with their groups and student workload.', 'ar' => 'قارن المعلمين حسب الحالة مع عبء المجموعات والطلاب لديهم.'],
                'teachers',
                ['full_name', 'teacher_status', 'job_title', 'assigned_groups_count', 'assisted_groups_count', 'active_groups_count', 'active_enrollments_count'],
                'teacher_status',
                'bar',
                ['teachers'],
                [['operation' => 'count', 'field' => null], ['operation' => 'sum', 'field' => 'active_enrollments_count']],
            ),
            $this->template(
                'course-completion-overview',
                ['en' => 'Course completion overview', 'ar' => 'نظرة عامة على إنجاز الدورات'],
                ['en' => 'Review courses by status with their group and active enrolment totals.', 'ar' => 'راجع الدورات حسب الحالة مع إجمالي المجموعات والتسجيلات النشطة.'],
                'courses',
                ['course_name', 'academic_year', 'status', 'starts_on', 'ends_on', 'groups_count', 'active_enrollments_count'],
                'status',
                'bar',
                ['classes'],
                [['operation' => 'count', 'field' => null], ['operation' => 'sum', 'field' => 'active_enrollments_count']],
            ),
            $this->template(
                'finance-summary',
                ['en' => 'Finance transaction summary', 'ar' => 'ملخص الحركات المالية'],
                ['en' => 'Summarise ledger activity by transaction type while keeping detailed rows available.', 'ar' => 'لخص حركة السجل المالي حسب نوع الحركة مع إبقاء الصفوف التفصيلية متاحة.'],
                'finance_transactions',
                ['transaction_date', 'transaction_number', 'transaction_type', 'finance_category', 'cash_box', 'currency', 'amount', 'local_amount'],
                'transaction_type',
                'bar',
                ['finance'],
                [['operation' => 'count', 'field' => null], ['operation' => 'sum', 'field' => 'local_amount']],
            ),
        ];
    }

    private function template(
        string $key,
        array $name,
        array $description,
        string $source,
        array $fields,
        string $groupBy,
        string $presentation,
        array $modules,
        array $calculations = [['operation' => 'count', 'field' => null]],
    ): array {
        return [
            'key' => $key,
            'kind' => 'both',
            'name' => $name,
            'description' => $description,
            'modules' => $modules,
            'definition' => [
                'data_source' => $source,
                'selected_fields' => $fields,
                'calculations' => $calculations,
                'group_by' => $groupBy,
                'presentation' => ['type' => $presentation, 'density' => 'comfortable'],
                'filters' => [],
                'sort_field' => null,
                'sort_direction' => 'asc',
            ],
        ];
    }
};
