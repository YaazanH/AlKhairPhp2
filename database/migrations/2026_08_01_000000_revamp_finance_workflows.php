<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_categories', function (Blueprint $table): void {
            $table->boolean('is_donation')->default(false)->after('type');
        });

        Schema::table('finance_cash_box_transfers', function (Blueprint $table): void {
            $table->string('transfer_no')->nullable()->after('pair_uuid');
        });

        $prefix = strtoupper(trim((string) (DB::table('app_settings')
            ->where('group', 'finance')
            ->where('key', 'transfer_prefix')
            ->value('value') ?: 'TRSF')));

        DB::table('finance_cash_box_transfers')
            ->orderBy('id')
            ->get(['id', 'transfer_no'])
            ->each(function (object $transfer, int $index) use ($prefix): void {
                if (filled($transfer->transfer_no)) {
                    return;
                }

                DB::table('finance_cash_box_transfers')
                    ->where('id', $transfer->id)
                    ->update(['transfer_no' => sprintf('%s-%06d', $prefix ?: 'TRSF', $index + 1)]);
            });

        Schema::table('finance_cash_box_transfers', function (Blueprint $table): void {
            $table->unique('transfer_no');
        });

        Schema::table('finance_transactions', function (Blueprint $table): void {
            $table->string('special_transaction_no')->nullable()->after('transaction_no')->index();
            $table->string('status', 20)->default('active')->after('metadata')->index();
            $table->foreignId('deleted_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->text('deletion_reason')->nullable()->after('deleted_by');
            $table->softDeletes();
        });

        Schema::table('finance_requests', function (Blueprint $table): void {
            $table->string('expense_no')->nullable()->after('request_no')->unique();
        });

        $expenseSequence = 0;
        DB::table('finance_requests')
            ->whereIn('type', ['pull', 'expense'])
            ->whereIn('status', ['accepted', 'settled'])
            ->orderBy('id')
            ->get(['id'])
            ->each(function (object $request) use (&$expenseSequence): void {
                DB::table('finance_requests')->where('id', $request->id)->update([
                    'expense_no' => sprintf('EXP-%06d', ++$expenseSequence),
                ]);
            });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('original_invoice_no')->nullable()->after('invoice_no');
            $table->string('original_image_path')->nullable()->after('notes');
            $table->timestamp('finalised_at')->nullable()->after('original_image_path');
            $table->foreignId('finalised_by')->nullable()->after('finalised_at')->constrained('users')->nullOnDelete();
        });

        DB::table('app_settings')->updateOrInsert(
            ['group' => 'finance', 'key' => 'transfer_prefix'],
            ['value' => $prefix ?: 'TRSF', 'type' => 'string', 'updated_at' => now(), 'created_at' => now()],
        );

        $teacherRoleId = DB::table('roles')->where('name', 'teacher')->where('guard_name', 'web')->value('id');
        $teacherPermissionIds = DB::table('permissions')
            ->where('guard_name', 'web')
            ->whereIn('name', ['finance.pull-requests.view', 'finance.pull-requests.create'])
            ->pluck('id');

        if ($teacherRoleId) {
            foreach ($teacherPermissionIds as $permissionId) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'role_id' => $teacherRoleId,
                ]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $teacherRoleId = DB::table('roles')->where('name', 'teacher')->where('guard_name', 'web')->value('id');
        $teacherPermissionIds = DB::table('permissions')
            ->where('guard_name', 'web')
            ->whereIn('name', ['finance.pull-requests.view', 'finance.pull-requests.create'])
            ->pluck('id');

        if ($teacherRoleId) {
            DB::table('role_has_permissions')->where('role_id', $teacherRoleId)->whereIn('permission_id', $teacherPermissionIds)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Schema::table('finance_requests', function (Blueprint $table): void {
            $table->dropUnique('finance_requests_expense_no_unique');
            $table->dropColumn('expense_no');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('finalised_by');
            $table->dropColumn(['original_invoice_no', 'original_image_path', 'finalised_at']);
        });

        Schema::table('finance_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropIndex(['special_transaction_no']);
            $table->dropIndex(['status']);
            $table->dropSoftDeletes();
            $table->dropColumn(['special_transaction_no', 'status', 'deletion_reason']);
        });

        Schema::table('finance_cash_box_transfers', function (Blueprint $table): void {
            $table->dropUnique('finance_cash_box_transfers_transfer_no_unique');
            $table->dropColumn('transfer_no');
        });

        Schema::table('finance_categories', function (Blueprint $table): void {
            $table->dropColumn('is_donation');
        });

        DB::table('app_settings')->where('group', 'finance')->where('key', 'transfer_prefix')->delete();
    }
};
