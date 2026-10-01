<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_support_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_support_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_tenant_administrator')->default(false);
            $table->text('message');
            $table->timestamps();

            $table->index(['tenant_support_request_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_support_messages');
    }
};
