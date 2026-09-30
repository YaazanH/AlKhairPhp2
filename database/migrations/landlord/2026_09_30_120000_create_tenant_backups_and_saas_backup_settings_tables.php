<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'landlord';

    public function up(): void
    {
        Schema::create('saas_backup_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_enabled')->default(true);
            $table->time('run_at')->default('02:00:00');
            $table->unsignedInteger('retention_count')->default(30);
            $table->timestamps();
        });

        Schema::create('tenant_backups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('source_backup_uuid')->nullable();
            $table->string('disk');
            $table->string('file_path');
            $table->string('filename');
            $table->string('trigger')->index();
            $table->string('status')->index();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->json('manifest_summary')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('restored_at')->nullable();
            $table->unsignedInteger('restore_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_backups');
        Schema::dropIfExists('saas_backup_settings');
    }
};
