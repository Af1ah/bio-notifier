<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void{Schema::table('device_commands',fn(Blueprint $table)=>$table->boolean('outcome_unknown')->default(false));}
 public function down():void{Schema::table('device_commands',fn(Blueprint $table)=>$table->dropColumn('outcome_unknown'));}
};
