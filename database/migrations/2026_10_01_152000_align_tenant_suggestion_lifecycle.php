<?php

use App\Models\TenantSupportRequest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_support_requests', function (Blueprint $table): void {
            $table->text('decline_reason')->nullable()->after('tenant_admin_note');
        });

        DB::table('tenant_support_requests')
            ->where('type', TenantSupportRequest::TYPE_SUGGESTION)
            ->whereIn('status', [TenantSupportRequest::STATUS_PLANNED, TenantSupportRequest::STATUS_IN_PROGRESS])
            ->update(['status' => TenantSupportRequest::STATUS_UNDER_REVIEW]);
        DB::table('tenant_support_requests')
            ->where('type', TenantSupportRequest::TYPE_SUGGESTION)
            ->where('status', TenantSupportRequest::STATUS_RELEASED)
            ->update(['status' => TenantSupportRequest::STATUS_IMPLEMENTED_INTERNALLY]);
    }

    public function down(): void
    {
        Schema::table('tenant_support_requests', function (Blueprint $table): void {
            $table->dropColumn('decline_reason');
        });
    }
};
