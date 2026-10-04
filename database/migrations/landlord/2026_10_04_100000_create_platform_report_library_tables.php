<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'landlord';

    public function up(): void
    {
        Schema::create('platform_report_library_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('kind', 20)->default('both');
            $table->json('name');
            $table->json('description')->nullable();
            $table->json('draft_definition');
            $table->json('required_modules');
            $table->unsignedInteger('latest_version')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('platform_administrators')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('platform_administrators')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('platform_report_library_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('library_item_id')->constrained('platform_report_library_items')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('kind', 20);
            $table->json('name');
            $table->json('description')->nullable();
            $table->json('definition');
            $table->json('required_modules');
            $table->foreignId('published_by')->nullable()->constrained('platform_administrators')->nullOnDelete();
            $table->timestamp('published_at');
            $table->timestamps();
            $table->unique(['library_item_id', 'version']);
        });

        Schema::table('platform_report_library_items', function (Blueprint $table): void {
            $table->foreignId('published_revision_id')->nullable()->after('latest_version')
                ->constrained('platform_report_library_revisions')->nullOnDelete();
        });

        foreach ([
            ['manage.report-library', 'Manage report library'],
            ['publish.report-library', 'Publish report library'],
        ] as [$code, $name]) {
            DB::connection('landlord')->table('platform_permissions')->insertOrIgnore([
                'code' => $code,
                'name' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('platform_report_library_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('published_revision_id');
        });
        Schema::dropIfExists('platform_report_library_revisions');
        Schema::dropIfExists('platform_report_library_items');
        DB::connection('landlord')->table('platform_permissions')->whereIn('code', [
            'manage.report-library',
            'publish.report-library',
        ])->delete();
    }
};
