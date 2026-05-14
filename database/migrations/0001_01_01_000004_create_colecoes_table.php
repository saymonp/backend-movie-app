<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('colecoes', function (Blueprint $table) {
            $table->id();
            $table->integer('tmdb_id')->unique();
            $table->text('nome');
            $table->text('poster_path')->nullable();
            $table->text('poster_thumb')->nullable();
            $table->text('backdrop_path')->nullable();
            $table->timestamps(); // Recomendado pelo Laravel (created_at/updated_at)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('colecoes');
    }
};
