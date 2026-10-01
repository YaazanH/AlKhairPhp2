<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_support_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_support_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 127);
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();

            $table->index(['tenant_support_request_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_support_attachments');
    }
};
