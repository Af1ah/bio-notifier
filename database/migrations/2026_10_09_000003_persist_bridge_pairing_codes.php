<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::table('site_bridges',fn(Blueprint $table)=>$table->text('pairing_code')->nullable());}
 public function down():void {Schema::table('site_bridges',fn(Blueprint $table)=>$table->dropColumn('pairing_code'));}
};
