<?php
use Illuminate\Database\Migrations\Migration;use Illuminate\Support\Facades\DB;
return new class extends Migration {public function up():void{foreach(['support.problems.submit','support.suggestions.submit','support.manage'] as $name){DB::table('permissions')->insertOrIgnore(['name'=>$name,'guard_name'=>'web','created_at'=>now(),'updated_at'=>now()]);}}public function down():void{DB::table('permissions')->whereIn('name',['support.problems.submit','support.suggestions.submit'])->delete();}};
