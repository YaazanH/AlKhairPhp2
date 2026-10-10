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
        if ($connection->table('platform_report_library_items')->where('system_key', 'group-memorization-ranking')->exists()) {
            return;
        }

        $name = ['en' => 'Group memorization ranking', 'ar' => 'ترتيب المجموعات في الحفظ'];
        $description = [
            'en' => 'Rank the top groups by memorized pages within the selected date range and show their session counts.',
            'ar' => 'رتّب أفضل المجموعات حسب الصفحات المحفوظة ضمن الفترة المحددة واعرض عدد جلساتها.',
        ];
        $definition = [
            'data_source' => 'memorization_sessions',
            'selected_fields' => ['recorded_on', 'student_number', 'full_name', 'pages_count', 'teacher_name', 'group_name'],
            'calculations' => [
                ['operation' => 'count', 'field' => null],
                ['operation' => 'sum', 'field' => 'pages_count'],
            ],
            'group_by' => 'group_name',
            'presentation' => [
                'type' => 'ranking',
                'density' => 'comfortable',
                'metric' => 'report_calculation_1',
            ],
            'filters' => ['status' => 'all', 'date_from' => '', 'date_to' => ''],
            'sort_field' => 'recorded_on',
            'sort_direction' => 'desc',
        ];
        $modules = ['memorization'];
        $now = now();
        $itemId = $connection->table('platform_report_library_items')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'system_key' => 'group-memorization-ranking',
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
            ->where('system_key', 'group-memorization-ranking')
            ->delete();
    }
};
