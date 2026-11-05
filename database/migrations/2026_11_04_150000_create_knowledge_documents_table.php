<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_documents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('category')->index();
            $table->string('kind', 40);
            $table->string('reference')->nullable();
            $table->string('summary', 400);
            $table->longText('content');
            $table->boolean('active')->default(true);
            $table->string('source_status', 20)->default('pending');
            $table->string('source_note', 500)->nullable();
            $table->timestamps();

            // Approved, active sources, latest('updated_at').
            $table->index(['active', 'source_status', 'updated_at']);
            // Admin list: latest('updated_at').
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_documents');
    }
};
