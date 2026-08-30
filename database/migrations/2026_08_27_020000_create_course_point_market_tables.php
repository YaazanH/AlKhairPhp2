<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('course_point_market_departments')) {
            Schema::create('course_point_market_departments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('course_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->decimal('point_price', 18, 2)->default(1);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['course_id', 'name']);
            });
        }

        if (! Schema::hasTable('course_point_market_invoices')) {
            Schema::create('course_point_market_invoices', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('course_id')->constrained()->cascadeOnDelete();
                $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
                $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['course_id', 'invoice_id']);
            });
        }

        if (! Schema::hasTable('course_point_market_items')) {
            Schema::create('course_point_market_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('course_id')->constrained()->cascadeOnDelete();
                $table->foreignId('course_point_market_department_id');
                $table->foreign('course_point_market_department_id', 'point_market_items_department_fk')
                    ->references('id')->on('course_point_market_departments')->cascadeOnDelete();
                $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('invoice_item_id')->nullable()->constrained()->nullOnDelete();
                $table->string('item_name');
                $table->decimal('quantity', 12, 2)->default(1);
                $table->decimal('unit_price', 18, 2);
                $table->string('currency_code', 10);
                $table->string('currency_symbol', 20)->nullable();
                $table->unsignedTinyInteger('currency_decimal_places')->default(2);
                $table->decimal('local_unit_price', 18, 2);
                $table->string('local_currency_code', 10);
                $table->string('local_currency_symbol', 20)->nullable();
                $table->unsignedTinyInteger('local_currency_decimal_places')->default(2);
                $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['course_id', 'invoice_item_id']);
                $table->index(['course_id', 'invoice_id']);
            });
        }

        $this->repairInterruptedItemsTable();
    }

    public function down(): void
    {
        Schema::dropIfExists('course_point_market_items');
        Schema::dropIfExists('course_point_market_invoices');
        Schema::dropIfExists('course_point_market_departments');
    }

    private function repairInterruptedItemsTable(): void
    {
        $foreignKeys = collect(Schema::getForeignKeys('course_point_market_items'));
        $foreignKeyDefinitions = [
            'course_id' => ['courses', 'cascade', 'point_market_items_course_fk'],
            'course_point_market_department_id' => ['course_point_market_departments', 'cascade', 'point_market_items_department_fk'],
            'invoice_id' => ['invoices', 'set null', 'point_market_items_invoice_fk'],
            'invoice_item_id' => ['invoice_items', 'set null', 'point_market_items_invoice_item_fk'],
            'added_by' => ['users', 'set null', 'point_market_items_added_by_fk'],
        ];

        foreach ($foreignKeyDefinitions as $column => [$foreignTable, $deleteAction, $constraintName]) {
            if ($foreignKeys->contains(fn (array $foreignKey): bool => $foreignKey['columns'] === [$column])) {
                continue;
            }

            Schema::table('course_point_market_items', function (Blueprint $table) use ($column, $foreignTable, $deleteAction, $constraintName): void {
                $foreign = $table->foreign($column, $constraintName)->references('id')->on($foreignTable);

                $deleteAction === 'cascade' ? $foreign->cascadeOnDelete() : $foreign->nullOnDelete();
            });
        }

        if (! Schema::hasIndex('course_point_market_items', ['course_id', 'invoice_item_id'], 'unique')) {
            Schema::table('course_point_market_items', function (Blueprint $table): void {
                $table->unique(['course_id', 'invoice_item_id']);
            });
        }

        if (! Schema::hasIndex('course_point_market_items', ['course_id', 'invoice_id'])) {
            Schema::table('course_point_market_items', function (Blueprint $table): void {
                $table->index(['course_id', 'invoice_id']);
            });
        }
    }
};
