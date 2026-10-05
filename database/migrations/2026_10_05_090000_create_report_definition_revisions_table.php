<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_definition_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_definition_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision_number');
            $table->string('action', 30);
            $table->json('snapshot');
            $table->unsignedInteger('restored_from_revision_number')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['report_definition_id', 'revision_number'],
                'report_revision_definition_number_unique',
            );
        });

        DB::table('report_definitions')->orderBy('id')->get()->each(function (object $definition): void {
            $decode = static function (mixed $value): mixed {
                if (! is_string($value)) {
                    return $value;
                }

                $decoded = json_decode($value, true);

                return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
            };

            DB::table('report_definition_revisions')->insert([
                'report_definition_id' => $definition->id,
                'revision_number' => 1,
                'action' => 'baseline',
                'snapshot' => json_encode([
                    'name' => $definition->name,
                    'description' => $definition->description,
                    'data_source' => $definition->data_source,
                    'selected_fields' => $decode($definition->selected_fields),
                    'calculations' => $decode($definition->calculations),
                    'group_by' => $definition->group_by,
                    'presentation' => $decode($definition->presentation),
                    'filters' => $decode($definition->filters),
                    'sort_field' => $definition->sort_field,
                    'sort_direction' => $definition->sort_direction,
                ], JSON_THROW_ON_ERROR),
                'restored_from_revision_number' => null,
                'created_by' => $definition->updated_by ?: $definition->created_by,
                'created_at' => $definition->updated_at ?: now(),
                'updated_at' => $definition->updated_at ?: now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_definition_revisions');
    }
};
