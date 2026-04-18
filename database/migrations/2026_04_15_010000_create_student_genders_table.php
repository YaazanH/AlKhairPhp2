<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_genders', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        $now = now();

        DB::table('student_genders')->upsert([
            ['code' => 'male', 'name' => 'Male', 'sort_order' => 10, 'is_active' => true, 'is_default' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'female', 'name' => 'Female', 'sort_order' => 20, 'is_active' => true, 'is_default' => false, 'created_at' => $now, 'updated_at' => $now],
        ], ['code'], ['name', 'sort_order', 'is_active', 'is_default', 'updated_at']);
    }

    public function down(): void
    {
        Schema::dropIfExists('student_genders');
    }
};
