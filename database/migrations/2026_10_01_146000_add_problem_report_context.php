<?php
use Illuminate\Database\Migrations\Migration;use Illuminate\Database\Schema\Blueprint;use Illuminate\Support\Facades\Schema;
return new class extends Migration {public function up():void{Schema::table('tenant_support_requests',function(Blueprint $t){$t->string('priority',12)->nullable()->after('type');$t->string('reported_url',2048)->nullable()->after('message');$t->string('browser_info',1000)->nullable()->after('reported_url');});}public function down():void{Schema::table('tenant_support_requests',function(Blueprint $t){$t->dropColumn(['priority','reported_url','browser_info']);});}};
