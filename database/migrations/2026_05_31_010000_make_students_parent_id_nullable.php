<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys=OFF');
            DB::statement(<<<'SQL'
CREATE TABLE students_new (
    id integer primary key autoincrement not null,
    user_id integer null,
    parent_id integer null,
    first_name varchar not null,
    last_name varchar not null,
    student_number varchar null,
    birth_date date not null,
    gender varchar null,
    school_name varchar null,
    grade_level_id integer null,
    quran_current_juz_id integer null,
    photo_path varchar null,
    status varchar not null default 'active',
    joined_at date null,
    notes text null,
    created_at datetime null,
    updated_at datetime null,
    deleted_at datetime null,
    foreign key(user_id) references users(id) on delete set null,
    foreign key(parent_id) references parents(id) on delete restrict,
    foreign key(grade_level_id) references grade_levels(id) on delete set null,
    foreign key(quran_current_juz_id) references quran_juzs(id) on delete set null
)
SQL);

            DB::statement(<<<'SQL'
INSERT INTO students_new (
    id,
    user_id,
    parent_id,
    first_name,
    last_name,
    student_number,
    birth_date,
    gender,
    school_name,
    grade_level_id,
    quran_current_juz_id,
    photo_path,
    status,
    joined_at,
    notes,
    created_at,
    updated_at,
    deleted_at
)
SELECT
    id,
    user_id,
    parent_id,
    first_name,
    last_name,
    student_number,
    birth_date,
    gender,
    school_name,
    grade_level_id,
    quran_current_juz_id,
    photo_path,
    status,
    joined_at,
    notes,
    created_at,
    updated_at,
    deleted_at
FROM students
SQL);

            DB::statement('DROP TABLE students');
            DB::statement('ALTER TABLE students_new RENAME TO students');
            DB::statement('CREATE UNIQUE INDEX students_user_id_unique ON students (user_id)');
            DB::statement('CREATE INDEX students_parent_id_status_index ON students (parent_id, status)');
            DB::statement('CREATE INDEX students_last_name_first_name_index ON students (last_name, first_name)');
            DB::statement('PRAGMA foreign_keys=ON');

            return;
        }

        Schema::table('students', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
        });

        Schema::table('students', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_id')->nullable()->change();
        });

        Schema::table('students', function (Blueprint $table) {
            $table
                ->foreign('parent_id')
                ->references('id')
                ->on('parents')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('students')->whereNull('parent_id')->exists()) {
            throw new RuntimeException('Cannot make students.parent_id required again while null parent links exist.');
        }

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys=OFF');
            DB::statement(<<<'SQL'
CREATE TABLE students_new (
    id integer primary key autoincrement not null,
    user_id integer null,
    parent_id integer not null,
    first_name varchar not null,
    last_name varchar not null,
    student_number varchar null,
    birth_date date not null,
    gender varchar null,
    school_name varchar null,
    grade_level_id integer null,
    quran_current_juz_id integer null,
    photo_path varchar null,
    status varchar not null default 'active',
    joined_at date null,
    notes text null,
    created_at datetime null,
    updated_at datetime null,
    deleted_at datetime null,
    foreign key(user_id) references users(id) on delete set null,
    foreign key(parent_id) references parents(id) on delete restrict,
    foreign key(grade_level_id) references grade_levels(id) on delete set null,
    foreign key(quran_current_juz_id) references quran_juzs(id) on delete set null
)
SQL);

            DB::statement(<<<'SQL'
INSERT INTO students_new (
    id,
    user_id,
    parent_id,
    first_name,
    last_name,
    student_number,
    birth_date,
    gender,
    school_name,
    grade_level_id,
    quran_current_juz_id,
    photo_path,
    status,
    joined_at,
    notes,
    created_at,
    updated_at,
    deleted_at
)
SELECT
    id,
    user_id,
    parent_id,
    first_name,
    last_name,
    student_number,
    birth_date,
    gender,
    school_name,
    grade_level_id,
    quran_current_juz_id,
    photo_path,
    status,
    joined_at,
    notes,
    created_at,
    updated_at,
    deleted_at
FROM students
SQL);

            DB::statement('DROP TABLE students');
            DB::statement('ALTER TABLE students_new RENAME TO students');
            DB::statement('CREATE UNIQUE INDEX students_user_id_unique ON students (user_id)');
            DB::statement('CREATE INDEX students_parent_id_status_index ON students (parent_id, status)');
            DB::statement('CREATE INDEX students_last_name_first_name_index ON students (last_name, first_name)');
            DB::statement('PRAGMA foreign_keys=ON');

            return;
        }

        Schema::table('students', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
        });

        Schema::table('students', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_id')->nullable(false)->change();
        });

        Schema::table('students', function (Blueprint $table) {
            $table
                ->foreign('parent_id')
                ->references('id')
                ->on('parents')
                ->restrictOnDelete();
        });
    }
};
