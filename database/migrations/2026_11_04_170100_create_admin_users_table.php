<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staff accounts for admin.gabaymo.com. Kept apart from customers and
     * partners (users table) so no customer data or role name can grant
     * admin access.
     */
    public function up(): void
    {
        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->foreignId('role_id')->constrained('roles')->restrictOnDelete();
            $table->string('password');
            $table->rememberToken();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            // Role page account lists, ordered by name.
            $table->index(['role_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_users');
    }
};
