<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('finance_report_templates')) {
            return;
        }

        if (! Schema::hasColumns('finance_report_templates', [
            'date_mode',
            'show_issuer_name',
            'show_page_numbers',
        ])) {
            return;
        }

        DB::table('finance_report_templates')
            ->whereNull('date_mode')
            ->update(['date_mode' => 'exported_at']);

        DB::table('finance_report_templates')
            ->whereNull('show_issuer_name')
            ->update(['show_issuer_name' => true]);

        DB::table('finance_report_templates')
            ->whereNull('show_page_numbers')
            ->update(['show_page_numbers' => false]);
    }

    public function down(): void
    {
        // Legacy-default normalization only.
    }
};
