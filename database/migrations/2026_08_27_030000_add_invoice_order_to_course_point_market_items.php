<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_point_market_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('invoice_sort_order')->nullable()->after('invoice_item_id');
            $table->unsignedBigInteger('invoice_item_sort_order')->nullable()->after('invoice_sort_order');
            $table->index(
                ['course_point_market_department_id', 'invoice_sort_order', 'invoice_item_sort_order'],
                'point_market_department_invoice_order_index',
            );
        });

        DB::table('course_point_market_items')
            ->select(['id', 'course_id', 'invoice_id', 'invoice_item_id'])
            ->orderBy('id')
            ->chunkById(200, function ($items): void {
                foreach ($items as $item) {
                    $invoiceOrder = DB::table('course_point_market_invoices')
                        ->where('course_id', $item->course_id)
                        ->where('invoice_id', $item->invoice_id)
                        ->value('id');
                    $invoiceItemOrder = DB::table('invoice_items')
                        ->where('id', $item->invoice_item_id)
                        ->value('line_no');

                    DB::table('course_point_market_items')
                        ->where('id', $item->id)
                        ->update([
                            'invoice_sort_order' => $invoiceOrder ?: $item->invoice_id,
                            'invoice_item_sort_order' => $invoiceItemOrder ?: $item->invoice_item_id,
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('course_point_market_items', function (Blueprint $table): void {
            $table->dropIndex('point_market_department_invoice_order_index');
            $table->dropColumn(['invoice_sort_order', 'invoice_item_sort_order']);
        });
    }
};
