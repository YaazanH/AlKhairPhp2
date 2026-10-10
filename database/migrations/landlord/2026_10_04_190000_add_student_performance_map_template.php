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
        if ($connection->table('platform_report_library_items')->where('system_key', 'student-performance-map')->exists()) {
            return;
        }

        $name = ['en' => 'Student performance map', 'ar' => 'خريطة أداء الطلاب'];
        $description = [
            'en' => 'Compare each active student’s memorized pages and points with the tenant averages.',
            'ar' => 'قارن الصفحات المحفوظة ونقاط كل طالب نشط مع متوسطات المؤسسة.',
        ];
        $definition = [
            'data_source' => 'students',
            'selected_fields' => ['student_number', 'full_name', 'current_group', 'memorized_pages', 'points_balance'],
            'calculations' => [
                ['operation' => 'count', 'field' => null],
                ['operation' => 'sum', 'field' => 'memorized_pages'],
                ['operation' => 'sum', 'field' => 'points_balance'],
            ],
            'group_by' => 'student_identity',
            'presentation' => [
                'type' => 'performance_map',
                'density' => 'comfortable',
                'x_metric' => 'report_calculation_1',
                'metric' => 'report_calculation_2',
            ],
            'filters' => ['status' => 'active'],
            'sort_field' => 'full_name',
            'sort_direction' => 'asc',
        ];
        $modules = ['students', 'points_rewards', 'memorization'];
        $now = now();
        $itemId = $connection->table('platform_report_library_items')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'system_key' => 'student-performance-map',
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
            ->where('system_key', 'student-performance-map')
            ->delete();
    }
};
