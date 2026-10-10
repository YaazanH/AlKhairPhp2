<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'landlord';

    public function up(): void
    {
        Schema::create('platform_support_cases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('tenant_support_request_id');
            $t->string('type', 20);
            $t->string('status', 30)->default('forwarded');
            $t->string('subject');
            $t->text('message');
            $t->timestamp('forwarded_at');
            $t->timestamps();
            $t->unique(['tenant_id', 'tenant_support_request_id'], 'platform_support_tenant_request_unique');
            $t->index(['status', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_support_cases');
    }
};
