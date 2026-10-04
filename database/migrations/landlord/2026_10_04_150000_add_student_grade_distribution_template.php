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
        if ($connection->table('platform_report_library_items')->where('system_key', 'students-by-grade-level')->exists()) {
            return;
        }

        $name = ['en' => 'Students by grade level', 'ar' => 'الطلاب حسب الصف الدراسي'];
        $description = [
            'en' => 'Compare the proportion of students assigned to each grade level.',
            'ar' => 'قارن نسبة الطلاب المسجلين في كل صف دراسي.',
        ];
        $definition = [
            'data_source' => 'students',
            'selected_fields' => ['student_number', 'full_name', 'status', 'grade_level', 'current_group'],
            'calculations' => [['operation' => 'count', 'field' => null]],
            'group_by' => 'grade_level',
            'presentation' => ['type' => 'treemap', 'density' => 'comfortable'],
            'filters' => [],
            'sort_field' => 'full_name',
            'sort_direction' => 'asc',
        ];
        $modules = ['students'];
        $now = now();
        $itemId = $connection->table('platform_report_library_items')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'system_key' => 'students-by-grade-level',
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
            ->where('system_key', 'students-by-grade-level')
            ->delete();
    }
};
