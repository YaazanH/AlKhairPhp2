<?php

use App\Support\RoleRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->unsignedSmallInteger('level')->default(0)->after('guard_name');
            $table->index('level');
        });

        foreach (RoleRegistry::defaultLevels() as $roleName => $level) {
            DB::table('roles')
                ->where('guard_name', 'web')
                ->where('name', $roleName)
                ->update(['level' => $level]);
        }
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropIndex(['level']);
            $table->dropColumn('level');
        });
    }
};
