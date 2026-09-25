<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('primary_color', 7);
            $table->string('secondary_color', 7);
            $table->string('logo_url')->nullable();
            $table->string('default_font')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brands');
    }
};
