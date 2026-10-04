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
        if ($connection->table('platform_report_library_items')->where('system_key', 'expenses-by-category')->exists()) {
            return;
        }

        $name = ['en' => 'Expenses by category', 'ar' => 'المصروفات حسب التصنيف'];
        $description = [
            'en' => 'Compare expense categories using absolute local-currency totals.',
            'ar' => 'قارن تصنيفات المصروفات باستخدام إجمالي القيم المطلقة بالعملة المحلية.',
        ];
        $definition = [
            'data_source' => 'finance_transactions',
            'selected_fields' => ['transaction_date', 'transaction_number', 'finance_category', 'cash_box', 'currency', 'amount', 'local_amount'],
            'calculations' => [
                ['operation' => 'count', 'field' => null],
                ['operation' => 'absolute_sum', 'field' => 'local_amount'],
            ],
            'group_by' => 'finance_category',
            'presentation' => ['type' => 'donut', 'density' => 'comfortable', 'metric' => 'report_calculation_1'],
            'filters' => ['status' => 'expense'],
            'sort_field' => 'transaction_date',
            'sort_direction' => 'desc',
        ];
        $modules = ['finance'];
        $now = now();
        $itemId = $connection->table('platform_report_library_items')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'system_key' => 'expenses-by-category',
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
            ->where('system_key', 'expenses-by-category')
            ->delete();
    }
};
