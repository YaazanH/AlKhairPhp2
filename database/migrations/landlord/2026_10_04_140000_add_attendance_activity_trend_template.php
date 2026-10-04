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
        if ($connection->table('platform_report_library_items')->where('system_key', 'attendance-activity-trend')->exists()) {
            return;
        }

        $name = ['en' => 'Attendance activity trend', 'ar' => 'اتجاه نشاط الحضور'];
        $description = [
            'en' => 'Track the number of recorded student attendance results in chronological order.',
            'ar' => 'تتبع عدد نتائج حضور الطلاب المسجلة بترتيب زمني.',
        ];
        $definition = [
            'data_source' => 'student_attendance',
            'selected_fields' => ['attendance_date', 'student_number', 'full_name', 'attendance_status', 'presence_result', 'group_name'],
            'calculations' => [['operation' => 'count', 'field' => null]],
            'group_by' => 'attendance_date',
            'presentation' => ['type' => 'line', 'density' => 'comfortable'],
            'filters' => [],
            'sort_field' => null,
            'sort_direction' => 'asc',
        ];
        $modules = ['student_attendance'];
        $now = now();
        $itemId = $connection->table('platform_report_library_items')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'system_key' => 'attendance-activity-trend',
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
            ->where('system_key', 'attendance-activity-trend')
            ->delete();
    }
};
