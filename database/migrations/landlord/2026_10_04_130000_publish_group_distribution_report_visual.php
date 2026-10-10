<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'landlord';

    public function up(): void
    {
        $connection = DB::connection('landlord');
        $item = $connection->table('platform_report_library_items')
            ->where('system_key', 'students-by-group')
            ->first();

        if (! $item) {
            return;
        }

        $definition = $this->decode($item->draft_definition);
        $definition['presentation'] = [
            'type' => 'lollipop',
            'density' => (string) data_get($definition, 'presentation.density', 'comfortable'),
        ];
        $now = now();
        $revisionId = $connection->table('platform_report_library_revisions')->insertGetId([
            'library_item_id' => $item->id,
            'version' => 2,
            'kind' => $item->kind,
            'name' => $item->name,
            'description' => $item->description,
            'definition' => json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'required_modules' => $item->required_modules,
            'published_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $connection->table('platform_report_library_items')->where('id', $item->id)->update([
            'draft_definition' => json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'latest_version' => 2,
            'published_revision_id' => $revisionId,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $connection = DB::connection('landlord');
        $item = $connection->table('platform_report_library_items')
            ->where('system_key', 'students-by-group')
            ->first();

        if (! $item) {
            return;
        }

        $revision = $connection->table('platform_report_library_revisions')
            ->where('library_item_id', $item->id)
            ->where('version', 1)
            ->first();

        if (! $revision) {
            return;
        }

        $connection->table('platform_report_library_items')->where('id', $item->id)->update([
            'draft_definition' => $revision->definition,
            'latest_version' => 1,
            'published_revision_id' => $revision->id,
            'updated_at' => now(),
        ]);
        $connection->table('platform_report_library_revisions')
            ->where('library_item_id', $item->id)
            ->where('version', 2)
            ->delete();
    }

    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        return json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
    }
};
