<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description', 300)->nullable();
            $table->boolean('is_system')->default(false);
            $table->json('permissions');
            $table->timestamps();

            // Role list: orderByDesc('is_system')->orderBy('name').
            $table->index(['is_system', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
