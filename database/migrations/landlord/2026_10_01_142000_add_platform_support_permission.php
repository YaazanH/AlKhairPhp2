<?php
use Illuminate\Database\Migrations\Migration;use Illuminate\Support\Facades\DB;
return new class extends Migration {protected $connection='landlord';public function up():void{DB::connection('landlord')->table('platform_permissions')->insertOrIgnore(['code'=>'manage.support','name'=>'Manage support cases','description'=>'Review and update forwarded tenant support requests.','created_at'=>now(),'updated_at'=>now()]);}public function down():void{DB::connection('landlord')->table('platform_permissions')->where('code','manage.support')->delete();}};
