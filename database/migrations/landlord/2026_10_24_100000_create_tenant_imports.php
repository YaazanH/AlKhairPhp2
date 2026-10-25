<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'landlord';

    public function up(): void
    {
        Schema::create('tenant_imports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('platform_administrator_id')->constrained()->restrictOnDelete();
            $table->foreignId('tenant_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->uuid('source_id')->nullable()->unique();
            $table->uuid('export_id')->nullable()->unique();
            $table->string('status', 32)->default('uploading')->index();
            $table->string('filename');
            $table->unsignedBigInteger('expected_bytes');
            $table->unsignedBigInteger('uploaded_bytes')->default(0);
            $table->unsignedInteger('next_chunk')->default(0);
            $table->unsignedInteger('progress')->default(0);
            $table->string('phase')->nullable();
            $table->string('error_code')->nullable();
            $table->string('archive_sha256', 64)->nullable();
            $table->json('summary')->nullable();
            $table->json('selection')->nullable();
            $table->string('preview_signature', 64)->nullable();
            $table->json('report')->nullable();
            $table->boolean('database_owned')->default(false);
            $table->boolean('database_allocation_started')->default(false);
            $table->boolean('public_storage_owned')->default(false);
            $table->boolean('private_storage_owned')->default(false);
            $table->string('cleanup_status')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamps();
        });
        Schema::create('tenant_import_jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_import_jobs');
        Schema::dropIfExists('tenant_imports');
    }
};
