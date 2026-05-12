<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_currencies', function (Blueprint $table): void {
            if (! Schema::hasColumn('finance_currencies', 'decimal_places')) {
                $table->unsignedTinyInteger('decimal_places')->default(2)->after('symbol');
            }
        });

        Schema::table('finance_requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('finance_requests', 'counterparty_name')) {
                $table->string('counterparty_name')->nullable()->after('requested_reason');
            }

            if (! Schema::hasColumn('finance_requests', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        Schema::create('print_page_sizes', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->decimal('page_width_mm', 8, 2);
            $table->decimal('page_height_mm', 8, 2);
            $table->decimal('margin_top_mm', 8, 2)->default(10);
            $table->decimal('margin_right_mm', 8, 2)->default(10);
            $table->decimal('margin_bottom_mm', 8, 2)->default(10);
            $table->decimal('margin_left_mm', 8, 2)->default(10);
            $table->decimal('gap_x_mm', 8, 2)->default(6);
            $table->decimal('gap_y_mm', 8, 2)->default(6);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        $now = now();

        DB::table('print_page_sizes')->insert([
            'name' => 'A4',
            'page_width_mm' => 210,
            'page_height_mm' => 297,
            'margin_top_mm' => 10,
            'margin_right_mm' => 10,
            'margin_bottom_mm' => 10,
            'margin_left_mm' => 10,
            'gap_x_mm' => 6,
            'gap_y_mm' => 6,
            'is_default' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('print_page_sizes');

        Schema::table('finance_requests', function (Blueprint $table): void {
            if (Schema::hasColumn('finance_requests', 'deleted_at')) {
                $table->dropSoftDeletes();
            }

            if (Schema::hasColumn('finance_requests', 'counterparty_name')) {
                $table->dropColumn('counterparty_name');
            }
        });

        Schema::table('finance_currencies', function (Blueprint $table): void {
            if (Schema::hasColumn('finance_currencies', 'decimal_places')) {
                $table->dropColumn('decimal_places');
            }
        });
    }
};
