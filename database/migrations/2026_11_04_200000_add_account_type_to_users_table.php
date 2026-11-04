<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('type', 20)->nullable()->after('role');
            $table->string('firm', 160)->nullable()->after('region');
            $table->unsignedSmallInteger('response_hours')->default(24)->after('firm');
            $table->string('partner_status', 20)->default('active')->after('response_hours');
        });

        DB::table('users')->where('role', 'member')->update(['type' => 'Users']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['type', 'firm', 'response_hours', 'partner_status']);
        });
    }
};
