<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('folder_id')->nullable()->constrained('folders')->nullOnDelete();
            $table->string('name');
            $table->string('type');
            $table->string('url');
            $table->json('tags')->nullable();
            $table->text('description')->nullable();
            $table->text('usage_suggestion')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'folder_id']);
            $table->index(['workspace_id', 'deleted_at']);
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
