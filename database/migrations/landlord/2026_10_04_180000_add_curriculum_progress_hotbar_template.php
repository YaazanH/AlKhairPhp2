<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    protected $connection = 'landlord';

    public function up(): void
    {
        $connection = DB::connection('landlord');
        if ($connection->table('platform_report_library_items')->where('system_key', 'curriculum-progress-by-group')->exists()) {
            return;
        }

        $name = ['en' => 'Curriculum progress by group', 'ar' => 'تقدم المنهاج حسب المجموعة'];
        $description = [
            'en' => 'Compare each group’s curriculum completion with its peers and show the equivalent lesson gap.',
            'ar' => 'قارن نسبة إنجاز المنهاج لكل مجموعة مع المجموعات الأخرى واعرض الفارق المكافئ بعدد الدروس.',
        ];
        $definition = [
            'data_source' => 'groups',
            'selected_fields' => [
                'group_name',
                'course_name',
                'teacher_name',
                'curriculum_name',
                'curriculum_completed_lessons',
                'curriculum_total_lessons',
                'curriculum_progress_percentage',
            ],
            'calculations' => [
                ['operation' => 'count', 'field' => null],
                ['operation' => 'avg', 'field' => 'curriculum_progress_percentage'],
                ['operation' => 'max', 'field' => 'curriculum_total_lessons'],
            ],
            'group_by' => 'group_name',
            'presentation' => [
                'type' => 'hotbar',
                'density' => 'comfortable',
                'metric' => 'report_calculation_1',
                'total_metric' => 'report_calculation_2',
            ],
            'filters' => ['status' => 'active'],
            'sort_field' => 'group_name',
            'sort_direction' => 'asc',
        ];
        $modules = ['classes', 'curriculum'];
        $now = now();
        $itemId = $connection->table('platform_report_library_items')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'system_key' => 'curriculum-progress-by-group',
            'is_system' => true,
            'kind' => 'both',
            'name' => json_encode($name, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'description' => json_encode($description, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'draft_definition' => json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'required_modules' => json_encode($modules, JSON_THROW_ON_ERROR),
            'latest_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $revisionId = $connection->table('platform_report_library_revisions')->insertGetId([
            'library_item_id' => $itemId,
            'version' => 1,
            'kind' => 'both',
            'name' => json_encode($name, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'description' => json_encode($description, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'definition' => json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'required_modules' => json_encode($modules, JSON_THROW_ON_ERROR),
            'published_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $connection->table('platform_report_library_items')->where('id', $itemId)->update([
            'published_revision_id' => $revisionId,
        ]);
    }

    public function down(): void
    {
        DB::connection('landlord')->table('platform_report_library_items')
            ->where('system_key', 'curriculum-progress-by-group')
            ->delete();
    }
};
