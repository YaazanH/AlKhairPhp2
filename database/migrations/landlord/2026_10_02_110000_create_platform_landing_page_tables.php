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
        Schema::create('platform_landing_pages', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->json('draft_content');
            $table->unsignedBigInteger('published_revision_id')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('platform_landing_page_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_landing_page_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision_number');
            $table->json('content');
            $table->foreignId('published_by_platform_administrator_id')->nullable();
            $table->foreign('published_by_platform_administrator_id', 'landing_revision_publisher_fk')
                ->references('id')
                ->on('platform_administrators')
                ->nullOnDelete();
            $table->timestamp('published_at');
            $table->timestamps();
            $table->unique(['platform_landing_page_id', 'revision_number'], 'platform_landing_revision_number_unique');
        });

        Schema::create('platform_landing_enquiries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('organisation_name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->text('message')->nullable();
            $table->string('locale', 12);
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });

        foreach ([
            ['manage.landing-page', 'Manage landing page drafts'],
            ['publish.landing-page', 'Publish landing page revisions'],
        ] as [$code, $name]) {
            DB::connection('landlord')->table('platform_permissions')->updateOrInsert(
                ['code' => $code],
                ['name' => $name, 'description' => null, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_landing_enquiries');
        Schema::dropIfExists('platform_landing_page_revisions');
        Schema::dropIfExists('platform_landing_pages');

        $permissionIds = DB::connection('landlord')->table('platform_permissions')
            ->whereIn('code', ['manage.landing-page', 'publish.landing-page'])
            ->pluck('id');
        DB::connection('landlord')->table('platform_permission_role')->whereIn('platform_permission_id', $permissionIds)->delete();
        DB::connection('landlord')->table('platform_permissions')->whereIn('id', $permissionIds)->delete();
    }
};
