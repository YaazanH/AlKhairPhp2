<?php
use Illuminate\Database\Migrations\Migration;use Illuminate\Database\Schema\Blueprint;use Illuminate\Support\Facades\Schema;
return new class extends Migration {protected $connection='landlord';public function up():void{Schema::table('platform_support_cases',function(Blueprint $t){$t->text('platform_note')->nullable()->after('message');$t->timestamp('platform_replied_at')->nullable()->after('platform_note');});}public function down():void{Schema::table('platform_support_cases',function(Blueprint $t){$t->dropColumn(['platform_note','platform_replied_at']);});}};
