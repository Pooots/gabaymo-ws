<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('school_name')->nullable()->after('email');
            $table->foreignId('subscription_plan_id')->nullable()->after('role')->constrained('subscription_plans')->nullOnDelete();
            $table->unsignedInteger('tokens_remaining')->default(0)->after('subscription_plan_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subscription_plan_id');
            $table->dropColumn(['school_name', 'tokens_remaining']);
        });
    }
};
